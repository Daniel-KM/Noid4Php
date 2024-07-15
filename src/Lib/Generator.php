<?php

namespace Noid\Lib;

use Exception;
use Noid\Noid;

class Generator
{
    /**
     * Convert a number to an extended digit according to $mask and $generator_type
     * and return (without prefix or NAAN).  A $mask character of 'k' gets
     * converted to '+' in the returned string; post-processing will eventually
     * turn it into a computed check character.
     *
     * @param int    $num
     * @param string $mask
     *
     * @return string
     * @throws Exception
     */
    public static function n2xdig($num, $mask)
    {
        $s = '';
        $div = null;
        $remainder = null;
        $c = null;

        # Check whether $mask is well-formed before proceeding.
        #
        if (!preg_match('/^[rsz][' . Globals::$repertoires . ']+k?$/', $mask)) {
            return '';
        }

        $var_width = 0;   # we start in fixed width part of the mask
        $rmask = array_reverse(str_split($mask));  # process each char in reverse
        $alphabet = '';
        while ($num != 0 || !$var_width) {
            if (!$var_width) {
                $c = array_shift($rmask);  # check next mask character,
                // Avoid to str_split() an empty value.
                if (strlen($c) == 0
                    || $c === 'r'
                    || $c === 's'
                ) { # terminate on r or s even if
                    break;   # $num is not all used up yet
                }
                if (isset(Globals::$alphabets[$c])) {
                    $alphabet = Globals::$alphabets[$c];
                    $div = strlen($alphabet);
                } elseif ($c === 'z') {
                    $var_width = 1;   # re-uses last $div value
                    continue;
                } elseif ($c === 'k') {
                    continue;
                }
            }
            $remainder = $num % $div;
            $num = intval($num / $div);
            $s .= $alphabet[$remainder];  // Append instead of prepend (O(1) vs O(n))
        }
        $s = strrev($s);  // Single reverse at end (O(n) total vs O(n²))
        if (substr($mask, -1) === 'k') {       # if it ends in a check character
            $s .= '+';      # represent it with plus in new id
        }
        return $s;
    }

    /**
     * Generate the actual next id to give out.  May be randomly or sequentially
     * selected.  This routine should not be called if there are ripe recyclable
     * identifiers to use.
     *
     * This routine and n2xdig comprise the real heart of the minter software.
     *
     * @param string $noid
     *
     * @return string|NULL
     * @throws Exception
     */
    public static function _genid($noid)
    {
        $db = Db::getDb($noid);
        if (is_null($db)) {
            return null;
        }

        Db::_dblock();

        # Variables:
        #   oacounter   overall counter's current value (last value minted)
        #   oatop   overall counter's greatest possible value of counter
        #   saclist (sub) active counters list
        #   siclist (sub) inactive counters list
        #   c$n/value   subcounter name's ($scn) value

        // Cache static values for performance.
        $oatop = Db::getCached('oatop');
        $longterm = Db::getCached('longterm');
        $wrap = Db::getCached('wrap');
        $generator_type = Db::getCached('generator_type');
        $mask = Db::getCached('mask');
        $percounter = Db::getCached('percounter');

        $oacounter = Db::$engine->get(Globals::_RR . "/oacounter");

        // Internally, _genid() is used only by mint() and seeded just before
        // with the last counter, so it can be set here to simplify generation.
        $seed = $oacounter;

        # yyy what are we going to do with counters for held? queued?

        if ($oatop != Globals::NOLIMIT && $oacounter >= $oatop) {
            # Critical test of whether we're willing to re-use identifiers
            # by re-setting (wrapping) the counter to zero.  To be extra
            # careful we check both the longterm and wrap settings, even
            # though, in theory, wrap won't be set if longterm is set.
            #
            if ($longterm || !$wrap) {
                Db::_dbunlock();
                $m = sprintf(
                    'error: identifiers exhausted (stopped at %1$s).',
                    $oatop
                );
                Log::addmsg($noid, $m);
                Log::logmsg($noid, $m);
                return null;
            }
            # If we get here, term is not "long".
            Log::logmsg($noid, sprintf(
                '%s: Resetting counter to zero; previously issued identifiers will be re-issued',
                Helper::getTemper()
            ));
            if ($generator_type === 'sequential') {
                Db::$engine->set(Globals::_RR."/oacounter", 0);
            } else {
                Db::_init_counters($noid);   # yyy calls dblock -- problem?
            }
            $oacounter = 0;
        }
        # If we get here, the counter may actually have just been reset.

        # Deal with the easy sequential generator case and exit early.
        #
        if ($generator_type === 'sequential') {
            $id = self::n2xdig($oacounter, $mask);
            Db::$engine->set(Globals::_RR."/oacounter", $oacounter + 1);   # incr to reflect new total
            Db::_dbunlock();
            return $id;
        }

        # If we get here, the generator must be of type "random".
        #
        $saclist = Db::$engine->get(Globals::_RR."/saclist");
        $saclist = explode(' ', trim($saclist));

        $len = count($saclist);
        if ($len < 1) {
            Db::_dbunlock();
            Log::addmsg($noid, sprintf(
                'error: no active counters panic, but %s identifiers left?',
                $oacounter
            ));
            return null;
        }

        switch (Noid::$random_generator) {
        case 'php rand()':     // Legacy (same as mt_rand in PHP 7.1+)
        case 'php mt_rand()':  // Legacy
        case 'mt_rand':
            mt_srand($seed);
            $randn = mt_rand(0, $len - 1);
            break;

        case 'PerlRandom':   // Legacy
        case 'Perl_Random':  // Legacy
        case 'perl rand()':  // Legacy
        case 'drand48':
        default:
            Noid::$_perlRandom->srand($seed);
            $randn = Noid::$_perlRandom->int_rand($len);
            break;
        }

        $sctrn = $saclist[$randn];   # at random; then pull its $n
        $n = substr($sctrn, 1);  # numeric equivalent from the name
        #print "randn=$randn, sctrn=$sctrn, counter n=$n\t";
        $sctr = Db::$engine->get(Globals::_RR."/$sctrn/value"); # and get its value
        $sctr++;                # increment and
        Db::$engine->set(Globals::_RR."/$sctrn/value", $sctr);    # store new current value
        Db::$engine->set(Globals::_RR."/oacounter", $oacounter + 1);  # incr overall counter
        # redundancy for sanity's sake

        # deal with an exhausted subcounter
        if ($sctr >= Db::$engine->get(Globals::_RR."/$sctrn/top")) {
            # remove from active counters list using array_filter (O(n) vs O(n²))
            $modsaclist = implode(' ', array_filter($saclist, function ($c) use ($sctrn) {
                return $c !== $sctrn; # inactive subcounters
            })) . ' ';
            Db::$engine->set(Globals::_RR."/saclist", $modsaclist);     # update saclist
            Db::$engine->set(Globals::_RR."/siclist", Db::$engine->get(Globals::_RR."/siclist") . ' ' . $sctrn);      # and siclist
            #print "===> Exhausted counter $sctrn\n";
        }

        # $sctr holds counter value, $n holds ordinal of the counter itself
        $id = self::n2xdig(
            $sctr + ($n * $percounter),
            $mask);
        Db::_dbunlock();
        return $id;
    }
}
