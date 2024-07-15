<?php
/**
 * Noid - Nice opaque identifiers (Php library).
 *
 * Strict conversion of the Perl module Noid-0.424 (21 April 2006) into php.
 *
 * @author Daniel Berthereau (port to php)
 * @license CeCILL-B v1.0 http://www.cecill.info/licences/Licence_CeCILL-B_V1-en.txt
 * @link https://metacpan.org/pod/distribution/Noid/noid
 * @link http://search.cpan.org/~jak/Noid/
 * @link https://github.com/Daniel-KM/Noid4Php
 * @package Noid
 * @version 1.3.0-0.424-php
 */

/**
 * Noid - Nice opaque identifiers (Perl module)
 *
 * Author: John A. Kunze, jak@ucop.edu, California Digital Library
 *  Originally created, UCSF/CKM, November 2002
 *
 * ---------
 * Copyright (c) 2002-2006 UC Regents
 *
 * Permission to use, copy, modify, distribute, and sell this software and
 * its documentation for any purpose is hereby granted without fee, provided
 * that (i) the above copyright notices and this permission notice appear in
 * all copies of the software and related documentation, and (ii) the names
 * of the UC Regents and the University of California are not used in any
 * advertising or publicity relating to the software without the specific,
 * prior written permission of the University of California.
 *
 * THE SOFTWARE IS PROVIDED "AS-IS" AND WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS, IMPLIED OR OTHERWISE, INCLUDING WITHOUT LIMITATION, ANY
 * WARRANTY OF MERCHANTABILITY OR FITNESS FOR A PARTICULAR PURPOSE.
 *
 * IN NO EVENT SHALL THE UNIVERSITY OF CALIFORNIA BE LIABLE FOR ANY
 * SPECIAL, INCIDENTAL, INDIRECT OR CONSEQUENTIAL DAMAGES OF ANY KIND,
 * OR ANY DAMAGES WHATSOEVER RESULTING FROM LOSS OF USE, DATA OR PROFITS,
 * WHETHER OR NOT ADVISED OF THE POSSIBILITY OF DAMAGE, AND ON ANY
 * THEORY OF LIABILITY, ARISING OUT OF OR IN CONNECTION WITH THE USE
 * OR PERFORMANCE OF THIS SOFTWARE.
 * ---------
 */

# yyy many comment blocks are very out of date -- need thorough review
# yyy make it so that http://uclibs.org/PID/foo maps to
#     ark.cdlib.org/ark:/13030/xzfoo  [ requirement from SCP meeting May 2004]
# yyy use "wantarray" function to return either number or message
#     when bailing out.
# yyy add cdlpid doc to pod ?
# yyy write about comparison with PURLs
# yyy check chars, authentication, ordinal stored in metadata
# yyy implement mod 4/8/16 distribution within large counter regions?
# yyy implement count-down counters as well as count-up?
# yyy make a shadow DB

# yyy upgrade ark-service and ERC.pm (which still use PDB.pm)

# yyy bindallow(), binddeny() ????

namespace Noid;

use Exception;
use Noid\Lib\Db;
use Noid\Lib\Generator;
use Noid\Lib\Globals;
use Noid\Lib\Helper;
use Noid\Lib\Log;
use Noid\Lib\PerlRandom;

/**
 * Create and manage noids.
 */
class Noid
{
    /**
     * For full compatibility with the perl script, the sequence must use the
     * same pseudo-random generator. The perl script uses int(rand()), that is
     * platform dependent. So, to make the sequence of a random-based template
     * predictable, that is required for long term maintenance, the same
     * generator should be used.
     *
     * @internal This value is used only for genid(), not for samples.
     *
     * Note: If the Suhosin patch is installed in php, srand() and mt_srand()
     * are disabled for encryption security reasons, so the noid can only be
     * sequential when this generator is set.
     *
     * @todo Add this information inside the database.
     *
     * @var string Can be "PerlRandom" (default), "perl rand()"; "php rand()"
     * or "php mt_rand()" (recommended but incompatible with the default Perl
     * script).
     */
    public static $random_generator = 'PerlRandom';

    /**
     * Contains the randomizer when the id generator is "PerlRandom".
     *
     * @var PerlRandom $_perlRandom
     */
    public static $_perlRandom;

    /**
     * DbEngine constructor.
     * @throws Exception
     */
    public function __construct()
    {
        // Db::$db_type should be set with dbopen(), dbcreate() or dbimport().
        self::init();
    }

    /**
     * Initialization.
     * * set the default time zone.
     * * create database interface entity.
     *
     * @throws Exception
     */
    public static function init()
    {
        // Make sure that this function is called only one time.
        static $init = false;
        if ($init) {
            return;
        }
        $init = true;

        // create database interface according to database option. added by Daniel Berthereau, 2019-07-29 00:00
        if (is_null(Db::$engine)) {
            $db_class = Globals::DB_TYPES[Db::$db_type];
            Db::$engine = new $db_class();
        }
        // function _dba_fetch_range() went as named "get_range()" to DatabaseInterface(BerkeleyDB and MysqlDB)
    }

    /**
     * Returns ANVL message on success, null on error.
     *
     * @param string $noid
     * @param string $contact
     * @param string $validate
     * @param string $how
     * @param string $id
     * @param string $elem
     * @param string $value
     *
     * @return string
     * @throws Exception
     */
    public static function bind($noid, $contact, $validate, $how, $id, $elem, $value)
    {
        // Db::$db_type should be set with dbopen(), dbcreate() or dbimport().
        self::init();

        $db = Db::getDb($noid);
        if (is_null($db)) {
            return null;
        }

        # yyy to add: incr, decr for $how;  possibly other ops (* + - / **)

        # Validate identifier and element if necessary.
        #
        # yyy to do: check $elem against controlled vocab
        #     (for errors more than for security)
        # yyy should this genonly setting be so capable of contradicting
        #     the $validate arg?
        if (Db::$engine->get(Globals::_RR . "/genonly")
            && $validate
            && !self::validate($noid, '-', $id)
        ) {
            return null;
        } elseif (strlen($id) == 0) {
            Log::addmsg($noid, 'error: bind needs an identifier specified.');
            return null;
        }
        if (empty($elem)) {
            Log::addmsg($noid, sprintf(
                'error: "bind %s" requires an element name.',
                $how
            ));
            return null;
        }

        # Transform and place a "hold" (if "long" term and we're not deleting)
        # on a special identifier.  Right now that means a user-entrered Id
        # of the form :idmap/Idpattern.  In this case, change it to a database
        # Id of the form Globals::_RR."/idmap/$elem", and change $elem to hold Idpattern;
        # this makes lookup faster and easier.
        #
        # First save original id and element names in $oid and $oelem to
        # use for all user messages; we use whatever is in $id and $elem
        # for actual database operations.
        $oid = $id;
        $oelem = $elem;
        $hold = 0;
        if (substr($id, 0, 1) === ':') {
            if (!preg_match('|^:idmap/(.+)|', $id, $matches)) {
                Log::addmsg($noid, sprintf(
                    'error: %s: id cannot begin with ":" unless of the form ":idmap/Idpattern".',
                    $oid
                ));
                return null;
            }
            $id = Globals::_RR . "/idmap/$oelem";
            $elem = $matches[1];
            if (Db::getCached('longterm')) {
                $hold = 1;
            }
        }
        # yyy transform other ids beginning with ":"?

        # Check circulation status.  Error if term is "long" and the id
        # hasn't been issued unless a hold was placed on it.
        #
        # Cache circ/hold keys for this id
        $circKey = "$id\t" . Globals::_RR . "/c";
        $holdKey = "$id\t" . Globals::_RR . "/h";
        # If no circ record and no hold…
        $ret_val = Db::$engine->get($circKey);
        if (empty($ret_val) && !Db::$engine->exists($holdKey)) {
            if (Db::getCached('longterm')) {
                Log::addmsg($noid, sprintf(
                    'error: %s: "long" term disallows binding an unissued identifier unless a hold is first placed on it.',
                    $oid
                ));
                return null;
            }
            Log::logmsg($noid, sprintf(
                'warning: %s: binding an unissued identifier that has no hold placed on it.',
                $oid
            ));
        } elseif (!in_array($how, Globals::$valid_hows)) {
            Log::addmsg($noid, sprintf(
                'error: bind how?  What does %s mean?',
                $how
            ));
            return null;
        }

        $peppermint = ($how === 'peppermint');
        if ($peppermint) {
            # yyy to do
            Log::addmsg($noid, 'error: bind "peppermint" not implemented.');
            return null;
        }

        # YYY bind mint file Elem Value     -- put into FILE by itself
        # YYY bind mint stuff_into_big_file Elem Value -- cat into file
        if ($how === 'mint' || $how === 'peppermint') {
            if ($id !== 'new') {
                Log::addmsg($noid, 'error: bind "mint" requires id to be given as "new".');
                return null;
            }
            $id = $oid = self::mint($noid, $contact, $peppermint);
            if (!$id) {
                return null;
            }
        }

        if ($how === 'delete' || $how === 'purge') {
            if (!empty($value)) {
                Log::addmsg($noid, sprintf(
                    'error: why does "bind %1$s" have a supplied value (%2$s)?"',
                    $how, $value
                ));
                return null;
            }
            $value = '';
        } elseif (empty($value)) {
            Log::addmsg($noid, sprintf(
                'error: "bind %1$s %2$s" requires a value to bind.',
                $how, $elem
            ));
            return null;
        }
        # If we get here, $value is defined and we can use with impunity.

        Db::_dblock();
        $ret_val = Db::$engine->get("$id\t$elem");
        if (empty($ret_val)) {      # currently unbound
            if (in_array($how, array('replace', 'append', 'prepend', 'delete'))) {
                Log::addmsg($noid, sprintf(
                    'error: for "bind %1$s", "%2$s %3$s" must already be bound.',
                    $how, $oid, $oelem
                ));
                Db::_dbunlock();
                return null;
            }
            Db::$engine->set("$id\t$elem", '');  # can concatenate with impunity
        } else {                      # currently bound
            if (in_array($how, array('new', 'mint', 'peppermint'))) {
                Log::addmsg($noid, sprintf(
                    'error: for "bind %1$s", "%2$s %3$s" cannot already be bound.',
                    $how, $oid, $oelem
                ));
                Db::_dbunlock();
                return null;
            }
        }
        # We don't care about bound/unbound for:  set, add, insert, purge

        $oldlen = strlen(Db::$engine->get("$id\t$elem"));
        $newlen = strlen($value);
        $statmsg = sprintf('%s bytes written', $newlen);

        if ($how === 'delete' || $how === 'purge') {
            Db::$engine->delete("$id\t$elem");
            $statmsg = "$oldlen bytes removed";
        } elseif ($how === 'add' || $how === 'append') {
            Db::$engine->set("$id\t$elem", Db::$engine->get("$id\t$elem") . $value);
            $statmsg .= " to the end of $oldlen bytes";
        } elseif ($how === 'insert' || $how === 'prepend') {
            Db::$engine->set("$id\t$elem", $value . Db::$engine->get("$id\t$elem"));
            $statmsg .= " to the beginning of $oldlen bytes";
        }
        // Else $how is "replace" or "set".
        else {
            Db::$engine->set("$id\t$elem", $value);
            $statmsg .= ", replacing $oldlen bytes";
        }

        if ($hold && Db::$engine->exists("$id\t$elem") && !self::hold_set($noid, $id)) {
            $hold = -1; # don't just bail out -- we need to unlock
        }

        # yyy $contact info ?  mainly for "long" term identifiers?
        Db::_dbunlock();

        return
            # yyy should this $id be or not be $oid???
            # yyy should labels for Id and Element be lowercased???
            "Id:      $id" . PHP_EOL
            . "Element: $elem" . PHP_EOL
            . "Bind:    $how" . PHP_EOL
            . "Status:  " . ($hold == -1 ? Log::errmsg($noid) : 'ok, ' . $statmsg) . PHP_EOL;
    }

    /**
     * Bind multiple elements in a single operation with one lock cycle.
     *
     * More efficient than calling bind() multiple times when binding
     * several elements, as it acquires the lock only once.
     *
     * @param string $noid     Database handle
     * @param string $contact  Contact information
     * @param string $validate Validation mode ('-' to skip)
     * @param array  $bindings Array of bindings, each with keys:
     *                         'how', 'id', 'elem', 'value'
     *
     * @return array Array of results (ANVL messages or null for each binding)
     * @throws Exception
     */
    public static function bindMultiple($noid, $contact, $validate, array $bindings)
    {
        if (empty($bindings)) {
            return [];
        }

        // Limit batch size
        if (count($bindings) > 10000) {
            Log::addmsg($noid, 'error: batch size cannot exceed 10000 bindings');
            return [];
        }

        // Db::$db_type should be set with dbopen(), dbcreate() or dbimport().
        self::init();

        $db = Db::getDb($noid);
        if (is_null($db)) {
            return [];
        }

        // Pre-validate all bindings before acquiring lock
        $genonly = Db::getCached('genonly');
        $longterm = Db::getCached('longterm');
        $validatedBindings = [];

        foreach ($bindings as $i => $binding) {
            $how = $binding['how'] ?? '';
            $id = $binding['id'] ?? '';
            $elem = $binding['elem'] ?? '';
            $value = $binding['value'] ?? '';

            // Validate identifier if necessary
            if ($genonly && $validate && !self::validate($noid, '-', $id)) {
                $validatedBindings[$i] = ['error' => true, 'result' => null];
                continue;
            }

            if (strlen($id) == 0) {
                Log::addmsg($noid, 'error: bind needs an identifier specified.');
                $validatedBindings[$i] = ['error' => true, 'result' => null];
                continue;
            }

            if (empty($elem)) {
                Log::addmsg($noid, sprintf('error: "bind %s" requires an element name.', $how));
                $validatedBindings[$i] = ['error' => true, 'result' => null];
                continue;
            }

            if (!in_array($how, Globals::$valid_hows)) {
                Log::addmsg($noid, sprintf('error: bind how? What does %s mean?', $how));
                $validatedBindings[$i] = ['error' => true, 'result' => null];
                continue;
            }

            if ($how === 'peppermint') {
                Log::addmsg($noid, 'error: bind "peppermint" not implemented.');
                $validatedBindings[$i] = ['error' => true, 'result' => null];
                continue;
            }

            if (($how === 'delete' || $how === 'purge') && !empty($value)) {
                Log::addmsg($noid, sprintf('error: why does "bind %1$s" have a supplied value (%2$s)?', $how, $value));
                $validatedBindings[$i] = ['error' => true, 'result' => null];
                continue;
            }

            if (!in_array($how, ['delete', 'purge']) && empty($value)) {
                Log::addmsg($noid, sprintf('error: "bind %1$s %2$s" requires a value to bind.', $how, $elem));
                $validatedBindings[$i] = ['error' => true, 'result' => null];
                continue;
            }

            // Transform :idmap/ identifiers
            $oid = $id;
            $oelem = $elem;
            $hold = 0;
            if (substr($id, 0, 1) === ':') {
                if (!preg_match('|^:idmap/(.+)|', $id, $matches)) {
                    Log::addmsg($noid, sprintf('error: %s: id cannot begin with ":" unless of the form ":idmap/Idpattern".', $oid));
                    $validatedBindings[$i] = ['error' => true, 'result' => null];
                    continue;
                }
                $id = Globals::_RR . "/idmap/$oelem";
                $elem = $matches[1];
                if ($longterm) {
                    $hold = 1;
                }
            }

            $validatedBindings[$i] = [
                'error' => false,
                'how' => $how,
                'id' => $id,
                'elem' => $elem,
                'value' => ($how === 'delete' || $how === 'purge') ? '' : $value,
                'oid' => $oid,
                'oelem' => $oelem,
                'hold' => $hold,
            ];
        }

        // Now acquire lock once and process all valid bindings
        Db::_dblock();

        $results = [];
        foreach ($validatedBindings as $i => $binding) {
            if ($binding['error']) {
                $results[$i] = null;
                continue;
            }

            $how = $binding['how'];
            $id = $binding['id'];
            $elem = $binding['elem'];
            $value = $binding['value'];
            $oid = $binding['oid'];
            $oelem = $binding['oelem'];
            $hold = $binding['hold'];

            $elemKey = "$id\t$elem";
            $currentValue = Db::$engine->get($elemKey);

            // Check circulation status for longterm
            $circKey = "$id\t" . Globals::_RR . "/c";
            $holdKey = "$id\t" . Globals::_RR . "/h";
            if (empty(Db::$engine->get($circKey)) && !Db::$engine->exists($holdKey)) {
                if ($longterm) {
                    Log::addmsg($noid, sprintf('error: %s: "long" term disallows binding an unissued identifier unless a hold is first placed on it.', $oid));
                    $results[$i] = null;
                    continue;
                }
                Log::logmsg($noid, sprintf('warning: %s: binding an unissued identifier that has no hold placed on it.', $oid));
            }

            // Handle mint operation
            if ($how === 'mint') {
                if ($id !== 'new') {
                    Log::addmsg($noid, 'error: bind "mint" requires id to be given as "new".');
                    $results[$i] = null;
                    continue;
                }
                // Note: For batch, mint inside lock is less efficient but maintains atomicity
                Db::_dbunlock();
                $id = $oid = self::mint($noid, $contact, 0);
                Db::_dblock();
                if (!$id) {
                    $results[$i] = null;
                    continue;
                }
                $elemKey = "$id\t$elem";
                $currentValue = Db::$engine->get($elemKey);
            }

            // Check bound/unbound state
            if (empty($currentValue)) {
                if (in_array($how, ['replace', 'append', 'prepend', 'delete'])) {
                    Log::addmsg($noid, sprintf('error: for "bind %1$s", "%2$s %3$s" must already be bound.', $how, $oid, $oelem));
                    $results[$i] = null;
                    continue;
                }
                Db::$engine->set($elemKey, '');
                $currentValue = '';
            } else {
                if (in_array($how, ['new', 'mint', 'peppermint'])) {
                    Log::addmsg($noid, sprintf('error: for "bind %1$s", "%2$s %3$s" cannot already be bound.', $how, $oid, $oelem));
                    $results[$i] = null;
                    continue;
                }
            }

            $oldlen = strlen($currentValue);
            $newlen = strlen($value);
            $statmsg = sprintf('%s bytes written', $newlen);

            if ($how === 'delete' || $how === 'purge') {
                Db::$engine->delete($elemKey);
                $statmsg = "$oldlen bytes removed";
            } elseif ($how === 'add' || $how === 'append') {
                Db::$engine->set($elemKey, $currentValue . $value);
                $statmsg .= " to the end of $oldlen bytes";
            } elseif ($how === 'insert' || $how === 'prepend') {
                Db::$engine->set($elemKey, $value . $currentValue);
                $statmsg .= " to the beginning of $oldlen bytes";
            } else {
                Db::$engine->set($elemKey, $value);
                $statmsg .= ", replacing $oldlen bytes";
            }

            if ($hold && Db::$engine->exists($elemKey) && !self::hold_set($noid, $id)) {
                $hold = -1;
            }

            $results[$i] = "Id:      $id" . PHP_EOL
                . "Element: $elem" . PHP_EOL
                . "Bind:    $how" . PHP_EOL
                . "Status:  " . ($hold == -1 ? Log::errmsg($noid) : 'ok, ' . $statmsg) . PHP_EOL;
        }

        Db::_dbunlock();

        return $results;
    }

    /**
     * Fetch elements from the base.
     *
     * @todo do we need to be able to "get/fetch" with a discriminant,
     *       eg, for smart multiple resolution??
     *
     * @param string       $noid
     * @param int          $verbose is 1 if we want labels, 0 if we don't
     * @param string       $id
     * @param array|string $elems
     *
     * @return string List of elements separated by an end of line.
     * @throws Exception
     */
    public static function fetch($noid, $verbose, $id, $elems)
    {
        // Db::$db_type should be set with dbopen(), dbcreate() or dbimport().
        self::init();

        if (strlen($id) == 0) {
            Log::addmsg($noid, sprintf(
                'error: %s requires that an identifier be specified.',
                $verbose ? 'fetch' : 'get'
            ));
            return null;
        }

        $db = Db::getDb($noid);
        if (is_null($db)) {
            return null;
        }

        if (!is_array($elems)) {
            $elems = strlen($elems) == 0 ? array() : array($elems);
        }

        $hdr = '';
        $retval = '';
        if ($verbose) {
            $hdr = "id:    $id"
                . (Db::$engine->exists("$id\t" . Globals::_RR . "/h") ? ' hold ' : '') . PHP_EOL
                . (self::validate($noid, '-', $id) ? '' : Log::errmsg($noid) . PHP_EOL)
                . 'Circ:  ' . (Db::$engine->get("$id\t" . Globals::_RR . "/c") ? : 'uncirculated') . PHP_EOL;
        }

        if (empty($elems)) {  # No elements were specified, so find them.
            $first = "$id\t";
            $values = Db::$engine->get_range($first);
            if ($values) {
                foreach ($values as $key => $value) {
                    $skip = preg_match('|^' . preg_quote("$first" . Globals::_RR . "/", '|') . '|', $key);
                    if (!$skip) {
                        # if $verbose (ie, fetch), include label and
                        # remember to strip "id\t" from front of $key
                        if ($verbose) {
                            $retval .= (preg_match('/^[^\t]*\t(.*)/', $key, $matches) ? $matches[1] : $key) . ': ';
                        }
                        $retval .= $value . PHP_EOL;
                    }
                }
            }

            if (empty($retval)) {
                Log::addmsg($noid, sprintf(
                    '%1$snote: no elements bound under %2$s.',
                    $hdr, $id
                ));
                return null;
            }
            return $hdr . $retval;
        }

        # yyy should this work for elem names with regexprs in them?
        # XXX idmap won't bind with longterm ???
        $idmapped = null;
        foreach ($elems as $elem) {
            if (Db::$engine->get("$id\t$elem")) {
                if ($verbose) {
                    $retval .= "$elem: ";
                }
                $retval .= Db::$engine->get("$id\t$elem") . PHP_EOL;
            } else {
                $idmapped = self::_id2elemval($noid, $verbose, $id, $elem);
                if ($verbose) {
                    $retval .= $idmapped
                        ? $idmapped . PHP_EOL . 'note: previous result produced by :idmap'
                        : sprintf('error: "%1$s %2$s" is not bound.', $id, $elem);
                    $retval .= PHP_EOL;
                } else {
                    $retval .= $idmapped . PHP_EOL;
                }
            }
        }

        return $hdr . $retval;
    }

    /**
     * Fetch elements for multiple identifiers in a single operation.
     *
     * More efficient than calling fetch() multiple times when fetching
     * data for several identifiers.
     *
     * @param string $noid     Database handle
     * @param int    $verbose  1 if we want labels, 0 if we don't
     * @param array  $requests Array of requests, each with keys:
     *                         'id' (required), 'elems' (optional array)
     *
     * @return array Array of results (string or null for each request)
     * @throws Exception
     */
    public static function fetchMultiple($noid, $verbose, array $requests)
    {
        if (empty($requests)) {
            return [];
        }

        // Limit batch size
        if (count($requests) > 10000) {
            Log::addmsg($noid, 'error: batch size cannot exceed 10000 requests');
            return [];
        }

        // Db::$db_type should be set with dbopen(), dbcreate() or dbimport().
        self::init();

        $db = Db::getDb($noid);
        if (is_null($db)) {
            return [];
        }

        $results = [];

        foreach ($requests as $i => $request) {
            $id = $request['id'] ?? '';
            $elems = $request['elems'] ?? [];

            if (strlen($id) == 0) {
                Log::addmsg($noid, sprintf(
                    'error: %s requires that an identifier be specified.',
                    $verbose ? 'fetch' : 'get'
                ));
                $results[$i] = null;
                continue;
            }

            if (!is_array($elems)) {
                $elems = strlen($elems) == 0 ? [] : [$elems];
            }

            // Cache keys for this id
            $holdKey = "$id\t" . Globals::_RR . "/h";
            $circKey = "$id\t" . Globals::_RR . "/c";

            $hdr = '';
            $retval = '';
            if ($verbose) {
                $hdr = "id:    $id"
                    . (Db::$engine->exists($holdKey) ? ' hold ' : '') . PHP_EOL
                    . (self::validate($noid, '-', $id) ? '' : Log::errmsg($noid) . PHP_EOL)
                    . 'Circ:  ' . (Db::$engine->get($circKey) ?: 'uncirculated') . PHP_EOL;
            }

            if (empty($elems)) {
                // No elements specified, fetch all
                $first = "$id\t";
                $values = Db::$engine->get_range($first);
                if ($values) {
                    foreach ($values as $key => $value) {
                        $skip = preg_match('|^' . preg_quote("$first" . Globals::_RR . "/", '|') . '|', $key);
                        if (!$skip) {
                            if ($verbose) {
                                $retval .= (preg_match('/^[^\t]*\t(.*)/', $key, $matches) ? $matches[1] : $key) . ': ';
                            }
                            $retval .= $value . PHP_EOL;
                        }
                    }
                }

                if (empty($retval)) {
                    Log::addmsg($noid, sprintf(
                        '%1$snote: no elements bound under %2$s.',
                        $hdr, $id
                    ));
                    $results[$i] = null;
                    continue;
                }
                $results[$i] = $hdr . $retval;
            } else {
                // Fetch specific elements
                $idmapped = null;
                foreach ($elems as $elem) {
                    $elemKey = "$id\t$elem";
                    $elemValue = Db::$engine->get($elemKey);
                    if ($elemValue) {
                        if ($verbose) {
                            $retval .= "$elem: ";
                        }
                        $retval .= $elemValue . PHP_EOL;
                    } else {
                        $idmapped = self::_id2elemval($noid, $verbose, $id, $elem);
                        if ($verbose) {
                            $retval .= $idmapped
                                ? $idmapped . PHP_EOL . 'note: previous result produced by :idmap'
                                : sprintf('error: "%1$s %2$s" is not bound.', $id, $elem);
                            $retval .= PHP_EOL;
                        } else {
                            $retval .= $idmapped . PHP_EOL;
                        }
                    }
                }
                $results[$i] = $hdr . $retval;
            }
        }

        return $results;
    }

    /**
     * Mint one or more identifiers.
     *
     * This routine produces a new identifier by taking a previously recycled
     * identifier from a queue (usually, a "used" identifier, but it might
     * have been pre-recycled) or by generating a brand new one.
     *
     * The $contact should be the initials or descriptive string to help
     * track who or what was happening at time of minting.
     *
     * @param string $noid    The database handle
     * @param string $contact Contact info for tracking
     * @param int    $pepper  Optional pepper value (unused, for compatibility)
     *
     * @return string|null Single identifier or null on error
     * @throws Exception
     */
    public static function mint($noid, $contact, $pepper = 0)
    {
        // Db::$db_type should be set with dbopen(), dbcreate() or dbimport().
        self::init();

        $db = Db::getDb($noid);
        if (is_null($db)) {
            return null;
        }

        if (empty($contact)) {
            Log::addmsg($noid, 'contact undefined');
            return null;
        }

        $template = Db::getCached('template');
        if (!$template) {
            Log::addmsg($noid, 'error: this minter does not generate identifiers (it does accept user-defined identifier and element bindings).');
            return null;
        }

        // Check pre-generation pool first (fastest path)
        $pregenId = self::_getFromPregenPool($noid, $contact);
        if ($pregenId !== null) {
            return $pregenId;
        }

        # Check if the head of the queue is ripe.  See comments under queue()
        # for an explanation of how the queue works.
        #
        $currdate = Helper::getTemper();        # fyi, 14 digits long
        $first = Globals::_RR . "/q/";

        # The following is not a proper loop.  Normally it should run once,
        # but several cycles may be needed to weed out anomalies with the id
        # at the head of the queue.  If all goes well and we found something
        # to mint from the queue, the last line in the loop exits the routine.
        # If we drop out of the loop, it's because the queue wasn't ripe.
        # Performance: Check queued counter first to avoid expensive scan if queue is empty.
        $queuedCount = (int) Db::$engine->get(Globals::_RR . "/queued");
        $values = $queuedCount > 0 ? Db::$engine->get_range($first) : [];
        foreach ($values as $key => $value) {
            $id = &$value;
            # The cursor, key and value are now set at the first item
            # whose key is greater than or equal to $first.  If the
            # queue was empty, there should be no items under Globals::_RR."/q/".
            #
            $qdate = preg_match('|' . preg_quote(Globals::_RR . "/q/", '|') . '(\d{14})|', $key, $matches) ? $matches[1] : null;
            if (empty($qdate)) {           # nothing in queue
                # this is our chance -- see queue() comments for why
                if (Db::$engine->get(Globals::_RR . "/fseqnum") > Globals::SEQNUM_MIN) {
                    Db::$engine->set(Globals::_RR . "/fseqnum", Globals::SEQNUM_MIN);
                }
                break;               # so move on
            }
            # If the date of the earliest item to re-use hasn't arrived
            if ($currdate < $qdate) {
                break;               # move on
            }

            # If we get here, head of queue is ripe.  Remove from queue.
            # Any "next" statement from now on in this loop discards the
            # queue element.
            #
            Db::$engine->delete($key);
            Db::$engine->set(Globals::_RR . "/queued", Db::$engine->get(Globals::_RR . "/queued") - 1);
            if (Db::$engine->get(Globals::_RR . "/queued") < 0) {
                $m = sprintf('error: queued count (%1$s) going negative on id %2$s', Db::$engine->get(Globals::_RR . "/queued"), $id);
                Log::addmsg($noid, $m);
                Log::logmsg($noid, $m);
                return null;
            }

            # We perform a few checks first to see if we're actually
            # going to use this identifier.  First, if there's a hold,
            # remove it from the queue and check the queue again.
            #
            if (Db::$engine->exists("$id\t" . Globals::_RR . "/h")) {     # if there's a hold
                if (Db::getCached('longterm')) {
                    Log::logmsg($noid, sprintf(
                        'warning: id %s found in queue with a hold placed on it -- removed from queue.',
                        $id
                    ));
                }
                continue;
            }
            # yyy this means id on "hold" can still have a 'q' circ status?

            $circ_svec = self::_get_circ_svec($noid, $id);

            if (substr($circ_svec, 0, 1) === 'i') {
                Log::logmsg($noid, sprintf(
                    'error: id %1$s appears to have been issued while still in the queue -- circ record is %2$s',
                    $id, Db::$engine->get("$id\t" . Globals::_RR . "/c")
                ));
                continue;
            }
            if (substr($circ_svec, 0, 1) === 'u') {
                Log::logmsg($noid, sprintf(
                    'note: id %1$s, marked as unqueued, is now being removed/skipped in the queue -- circ record is %2$s',
                    $id, Db::$engine->get("$id\t" . Globals::_RR . "/c")
                ));
                continue;
            }
            if (preg_match('/^([^q])/', $circ_svec, $matches)) {
                Log::logmsg($noid, sprintf(
                    'error: id %1$s found in queue has an unknown circ status (%2$s) -- circ record is %3$s',
                    $id, $matches[1], Db::$engine->get("$id\t" . Globals::_RR . "/c")
                ));
                continue;
            }

            # Finally, if there's no circulation record, it means that
            # it was queued to get it minted earlier or later than it
            # would normally be minted.  Log if term is "long".
            #
            if ($circ_svec === '') {
                if (Db::getCached('longterm')) {
                    Log::logmsg($noid, sprintf(
                        'note: queued id %s coming out of queue on first minting (pre-cycled)',
                        $id
                    ));
                }
            }

            # If we get here, our identifier has now passed its tests.
            # Do final identifier signoff and return.
            #
            return self::_set_circ_rec($noid, $id, 'i' . $circ_svec, $currdate, $contact);
        }

        # If we get here, we're not getting an id from the queue.
        # Instead we have to generate one.
        #

        // Prepare the id generator for PerlRandom: keep the specified one.
        if (Db::getCached('generator_type') == 'random') {
            self::$random_generator = Db::getCached('generator_random') ?: self::$random_generator;
            if (self::$random_generator == 'PerlRandom'
                // Kept for compatibility with old config.
                || self::$random_generator == 'Perl_Random'
            ) {
                self::$_perlRandom = PerlRandom::init();
            }
        }

        $addcheckchar = Db::getCached('addcheckchar');
        $repertoire = $addcheckchar
            ? (Db::getCached('checkrepertoire') ?: Helper::getAlphabet($template))
            : '';

        # As above, the following is not a proper loop.  Normally it should
        # run once, but several cycles may be needed to weed out anomalies
        # with the generated id (eg, there's a hold on the id, or it was
        # queued to delay issue).
        #
        while (true) {
            # Next is the important seeding of random number generator.
            # We need this so that we get the same exact series of
            # pseudo-random numbers, just in case we have to wipe out a
            # generator and start over.  That way, the n-th identifier
            # will be the same, no matter how often we have to start
            # over.  This step has no effect when $generator_type ==
            # "sequential".
            #
            srand((int) Db::$engine->get(Globals::_RR . "/oacounter"));

            # The id returned in this next step may have a "+" character
            # that n2xdig() appended to it.  The checkchar() routine
            # will convert it to a check character.
            #
            $id = Generator::_genid($noid);
            if (is_null($id)) {
                return null;
            }

            # Prepend NAAN and separator if there is a NAAN.
            #
            $firstpart = Db::getCached('firstpart');
            if ($firstpart) {
                $id = $firstpart . $id;
            }

            # Add check character if called for.
            #
            if ($addcheckchar) {
                $id = Helper::checkChar($id, $repertoire);
            }

            # There may be a hold on an id, meaning that it is not to
            # be issued (or re-issued).
            #
            if (Db::$engine->exists("$id\t" . Globals::_RR . "/h")) {     # if there's a hold
                continue;               # do _genid() again
            }

            # It's usual to find no circulation record.  However,
            # there may be a circulation record if the generator term
            # is not "long" and we've wrapped (restarted) the counter,
            # of if it was queued before first minting.  If the term
            # is "long", the generated id automatically gets a hold.
            #
            $circ_svec = self::_get_circ_svec($noid, $id);

            # A little unusual is the case when something has a
            # circulation status of 'q', meaning it has been queued
            # before first issue, presumably to get it minted earlier or
            # later than it would normally be minted; if the id we just
            # generated is marked as being in the queue (clearly not at
            # the head of the queue, or we would have seen it in the
            # previous while loop), we go to generate another id.  If
            # term is "long", log that we skipped this one.
            #
            if (substr($circ_svec, 0, 1) === 'q') {
                if (Db::getCached('longterm')) {
                    Log::logmsg($noid, sprintf(
                        'note: will not issue genid()’d %1$s as its status is "q", circ_rec is %2$s',
                        $id, Db::$engine->get("$id\t" . Globals::_RR . "/c")
                    ));
                }
                continue;
            }

            # If the circulation status is 'i' it means that the id is
            # being re-issued.  This shouldn't happen unless the counter
            # has wrapped around to the beginning.  If term is "long",
            # an id can be re-issued only if (a) its hold was released
            # and (b) it was placed in the queue (thus marked with 'q').
            #
            if (substr($circ_svec, 0, 1) === 'i'
                && (Db::getCached('longterm') || !Db::getCached('wrap'))
            ) {
                Log::logmsg($noid, sprintf(
                    'error: id %1$s cannot be re-issued except by going through the queue, circ_rec %2$s',
                    $id, Db::$engine->get("$id\t" . Globals::_RR . "/c")
                ));
                continue;
            }
            if (substr($circ_svec, 0, 1) === 'u') {
                Log::logmsg($noid, sprintf(
                    'note: generating id %1$s, currently marked as unqueued, circ record is %2$s',
                    $id, Db::$engine->get("$id\t" . Globals::_RR . "/c")
                ));
                continue;
            }
            if (preg_match('/^([^iqu])/', $circ_svec, $matches)) {
                Log::logmsg($noid, sprintf(
                    'error: id %1$s has unknown circulation status (%2$s), circ_rec %3$s',
                    $id, $matches[1], Db::$engine->get("$id\t" . Globals::_RR . "/c")
                ));
                continue;
            }
            #
            # Note that it's OK/normal if $circ_svec was an empty string.

            # If we get here, our identifier has now passed its tests.
            # Do final identifier signoff and return.
            #
            return self::_set_circ_rec($noid, $id, 'i' . $circ_svec, $currdate, $contact);
        }
        # yyy
        # Note that we don't assign any value to the very important key=$id.
        # What should it be bound to?  Let's decide later.

        # yyy
        # Often we want to bind an id initially even if the object or record
        # it identifies is "in progress", as this gives way to begin tracking,
        # eg, back to the person responsible.
        #
        return null;
    }

    /**
     * Mint multiple identifiers in a single operation.
     *
     * More efficient than calling mint() multiple times when minting
     * several identifiers, as it performs setup only once.
     *
     * @param string $noid    The database handle
     * @param string $contact Contact info for tracking
     * @param int    $count   Number of identifiers to mint (1-10000)
     * @param int    $pepper  Optional pepper value (unused, for compatibility)
     *
     * @return array Array of minted identifiers. May be shorter than $count
     *               if errors occur or minter is exhausted.
     * @throws Exception
     */
    public static function mintMultiple($noid, $contact, $count, $pepper = 0)
    {
        // Validate count
        $count = (int) $count;
        if ($count < 1) {
            Log::addmsg($noid, 'error: count must be at least 1');
            return [];
        }
        if ($count > 10000) {
            Log::addmsg($noid, 'error: count cannot exceed 10000 per batch');
            return [];
        }

        // Db::$db_type should be set with dbopen(), dbcreate() or dbimport().
        self::init();

        $db = Db::getDb($noid);
        if (is_null($db)) {
            return [];
        }

        if (empty($contact)) {
            Log::addmsg($noid, 'contact undefined');
            return [];
        }

        $template = Db::getCached('template');
        if (!$template) {
            Log::addmsg($noid, 'error: this minter does not generate identifiers (it does accept user-defined identifier and element bindings).');
            return [];
        }

        // Setup done once for entire batch
        $currdate = Helper::getTemper();
        $first = Globals::_RR . "/q/";

        // Prepare generator settings once
        if (Db::getCached('generator_type') == 'random') {
            self::$random_generator = Db::getCached('generator_random') ?: self::$random_generator;
            if (self::$random_generator == 'PerlRandom'
                || self::$random_generator == 'Perl_Random'
            ) {
                self::$_perlRandom = PerlRandom::init();
            }
        }

        $addcheckchar = Db::getCached('addcheckchar');
        $repertoire = $addcheckchar
            ? (Db::getCached('checkrepertoire') ?: Helper::getAlphabet($template))
            : '';
        $firstpart = Db::getCached('firstpart');
        $longterm = Db::getCached('longterm');
        $wrap = Db::getCached('wrap');

        $minted = [];

        for ($i = 0; $i < $count; $i++) {
            $id = self::_mintOne($noid, $contact, $currdate, $first, $template,
                $addcheckchar, $repertoire, $firstpart, $longterm, $wrap);

            if ($id === null) {
                // Check if minter is exhausted vs temporary error
                $total = Db::$engine->get(Globals::_RR . "/total");
                $oacounter = Db::$engine->get(Globals::_RR . "/oacounter");
                if ($total != Globals::NOLIMIT && $oacounter >= $total) {
                    Log::addmsg($noid, sprintf(
                        'error: minter exhausted after %d identifiers (total: %s)',
                        count($minted), $total
                    ));
                    break;
                }
                // For other errors, continue trying
                continue;
            }

            $minted[] = $id;
        }

        return $minted;
    }

    /**
     * Internal method to mint a single identifier with pre-computed values.
     *
     * @param string $noid
     * @param string $contact
     * @param string $currdate
     * @param string $first
     * @param string $template
     * @param bool   $addcheckchar
     * @param string $repertoire
     * @param string $firstpart
     * @param bool   $longterm
     * @param bool   $wrap
     *
     * @return string|null
     * @throws Exception
     */
    private static function _mintOne($noid, $contact, $currdate, $first, $template,
        $addcheckchar, $repertoire, $firstpart, $longterm, $wrap)
    {
        // Check queue first (with counter optimization)
        // Get ALL queue items and iterate with foreach/continue, like mint() does.
        // Using get_range with limit=1 and recursion doesn't work reliably
        // because database changes may not be visible in subsequent calls.
        $queuedCount = (int) Db::$engine->get(Globals::_RR . "/queued");
        $values = $queuedCount > 0 ? Db::$engine->get_range($first) : [];
        foreach ($values as $key => $value) {
            $id = &$value;
            $qdate = preg_match('|' . preg_quote(Globals::_RR . "/q/", '|') . '(\d{14})|', $key, $matches) ? $matches[1] : null;

            if (empty($qdate)) {
                if (Db::$engine->get(Globals::_RR . "/fseqnum") > Globals::SEQNUM_MIN) {
                    Db::$engine->set(Globals::_RR . "/fseqnum", Globals::SEQNUM_MIN);
                }
                break;
            }

            if ($currdate < $qdate) {
                break;
            }

            // Queue item is ripe - process it
            Db::$engine->delete($key);
            Db::$engine->set(Globals::_RR . "/queued", Db::$engine->get(Globals::_RR . "/queued") - 1);
            if (Db::$engine->get(Globals::_RR . "/queued") < 0) {
                $m = sprintf('error: queued count (%1$s) going negative on id %2$s', Db::$engine->get(Globals::_RR . "/queued"), $id);
                Log::addmsg($noid, $m);
                Log::logmsg($noid, $m);
                return null;
            }

            if (Db::$engine->exists("$id\t" . Globals::_RR . "/h")) {
                if ($longterm) {
                    Log::logmsg($noid, sprintf(
                        'warning: id %s found in queue with a hold placed on it -- removed from queue.',
                        $id
                    ));
                }
                continue;
            }

            $circ_svec = self::_get_circ_svec($noid, $id);

            if (substr($circ_svec, 0, 1) === 'i') {
                Log::logmsg($noid, sprintf(
                    'error: id %1$s appears to have been issued while still in the queue -- circ record is %2$s',
                    $id, Db::$engine->get("$id\t" . Globals::_RR . "/c")
                ));
                continue;
            }
            if (substr($circ_svec, 0, 1) === 'u') {
                Log::logmsg($noid, sprintf(
                    'note: id %1$s, marked as unqueued, is now being removed/skipped in the queue -- circ record is %2$s',
                    $id, Db::$engine->get("$id\t" . Globals::_RR . "/c")
                ));
                continue;
            }
            if (preg_match('/^([^q])/', $circ_svec, $matches)) {
                Log::logmsg($noid, sprintf(
                    'error: id %1$s found in queue has an unknown circ status (%2$s) -- circ record is %3$s',
                    $id, $matches[1], Db::$engine->get("$id\t" . Globals::_RR . "/c")
                ));
                continue;
            }

            if ($circ_svec === '') {
                if ($longterm) {
                    Log::logmsg($noid, sprintf(
                        'note: queued id %s coming out of queue on first minting (pre-cycled)',
                        $id
                    ));
                }
            }

            return self::_set_circ_rec($noid, $id, 'i' . $circ_svec, $currdate, $contact);
        }

        // Generate new ID
        $maxAttempts = 1000; // Prevent infinite loop
        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            srand((int) Db::$engine->get(Globals::_RR . "/oacounter"));

            $id = Generator::_genid($noid);
            if (is_null($id)) {
                return null;
            }

            if ($firstpart) {
                $id = $firstpart . $id;
            }

            if ($addcheckchar) {
                $id = Helper::checkChar($id, $repertoire);
            }

            if (Db::$engine->exists("$id\t" . Globals::_RR . "/h")) {
                continue;
            }

            $circ_svec = self::_get_circ_svec($noid, $id);

            if (substr($circ_svec, 0, 1) === 'q') {
                if ($longterm) {
                    Log::logmsg($noid, sprintf(
                        'note: will not issue genid()’d %1$s as its status is "q", circ_rec is %2$s',
                        $id, Db::$engine->get("$id\t" . Globals::_RR . "/c")
                    ));
                }
                continue;
            }

            if (substr($circ_svec, 0, 1) === 'i'
                && ($longterm || !$wrap)
            ) {
                Log::logmsg($noid, sprintf(
                    'error: id %1$s cannot be re-issued except by going through the queue, circ_rec %2$s',
                    $id, Db::$engine->get("$id\t" . Globals::_RR . "/c")
                ));
                continue;
            }
            if (substr($circ_svec, 0, 1) === 'u') {
                Log::logmsg($noid, sprintf(
                    'note: generating id %1$s, currently marked as unqueued, circ record is %2$s',
                    $id, Db::$engine->get("$id\t" . Globals::_RR . "/c")
                ));
                continue;
            }
            if (preg_match('/^([^iqu])/', $circ_svec, $matches)) {
                Log::logmsg($noid, sprintf(
                    'error: id %1$s has unknown circulation status (%2$s), circ_rec %3$s',
                    $id, $matches[1], Db::$engine->get("$id\t" . Globals::_RR . "/c")
                ));
                continue;
            }

            return self::_set_circ_rec($noid, $id, 'i' . $circ_svec, $currdate, $contact);
        }

        Log::addmsg($noid, 'error: exceeded maximum attempts to generate valid identifier');
        return null;
    }

    /**
     * An identifier may be queued to be issued/minted.  Usually this is used
     * to recycle a previously issued identifier, but it may also be used to
     * delay or advance the birth of an identifier that would normally be
     * issued in its own good time.  The $when argument may be "first", "lvf",
     * "delete", or a number and a letter designating units of seconds ('s',
     * the default) or days ('d') which is a delay added to the current time;
     * a $when of "now" means use the current time with no delay.
     *
     * The queue is composed of keys of the form ".Globals::_RR."/q/$qdate/$seqnum/$paddedid,
     * with the correponding values being the actual queued identifiers.  The
     * Btree allows us to step sequentially through the queue in an ordering
     * that is a side-effect of our key structure.  Left-to-right, it is
     *
     *   :/q/        ".Globals::_RR."/q/, 4 characters wide
     *   $qdate      14 digits wide, or 14 zeroes if "first" or "lvf"
     *   $seqnum     6 digits wide, or 000000 if "lvf"
     *   $paddedid   id "value", zero-padded on left, for "lvf"
     *
     * The $seqnum is there to help ensure queue order for up to a million queue
     * requests in a second (the granularity of our clock).  [ yyy $seqnum would
     * probably be obviated if we were using DB_DUP, but there's much conversion
     * involved with that ]
     *
     * We base our $seqnum (min is 1) on one of two stored sources:  "fseqnum"
     * for queue "first" requests or "gseqnum" for queue with a real time stamp
     * ("now" or delayed).  To implement queue "first", we use an artificial
     * time stamp of all zeroes, just like for "lvf"; to keep all "lvf" sorted
     * before "first" requests, we reset fseqnum and gseqnum to 1 (not zero).
     * We reset gseqnum whenever we use it at a different time from last time
     * since sort order will be guaranteed by different values of $qdate.  We
     * don't have that guarantee with the all-zeroes time stamp and fseqnum,
     * so we put off resetting fseqnum until it is over 500,000 and the queue
     * is empty, so we do then when checking the queue in mint().
     *
     * This key structure should ensure that the queue is sorted first by date.
     * As long as fewer than a million queue requests come in within a second,
     * we can make sure queue ordering is fifo.  To support "lvf" (lowest value
     * first) recycling, the $date and $seqnum fields are all zero, so the
     * ordering is determined entirely by the numeric "value" of identifier
     * (really only makes sense for a sequential generator); to achieve the
     * numeric sorting in the lexical Btree ordering, we strip off any prefix,
     * right-justify the identifier, and zero-pad on the left to create a number
     * that is 16 digits wider than the Template mask [yyy kludge that doesn't
     * take any overflow into account, or bigints for that matter].
     *
     * Returns the array of corresponding strings (errors and "id:" strings)
     * or an empty array on error.
     *
     * @param string       $noid
     * @param string       $contact
     * @param string       $when
     * @param array|string $ids
     *
     * @return array
     * @throws Exception
     */
    public static function queue($noid, $contact, $when, $ids)
    {
        // Db::$db_type should be set with dbopen(), dbcreate() or dbimport().
        self::init();

        $db = Db::getDb($noid);
        if (is_null($db)) {
            return null;
        }

        if (!is_array($ids)) {
            $ids = strlen($ids) == 0 ? array() : array($ids);
        }

        if (!Db::$engine->get(Globals::_RR . "/template")) {
            Log::addmsg($noid, 'error: queuing makes no sense in a bind-only minter.');
            return array();
        }
        if (empty($contact)) {
            Log::addmsg($noid, 'error: contact undefined');
            return array();
        }
        if (empty($when) || trim($when) === '') {
            Log::addmsg($noid, 'error: queue when? (eg, first, lvf, 30d, now)');
            return array();
        }
        # yyy what is sensible thing to do if no ids are present?
        if (empty($ids)) {
            Log::addmsg($noid, 'error: must specify at least one id to queue.');
            return array();
        }
        $seqnum = 0;
        $delete = 0;
        # purposely null
        $fixsqn = null;
        $qdate = null;

        # You can express a delay in days (d) or seconds (s, default).
        #
        if (preg_match('/^(\d+)([ds]?)$/', $when, $matches)) {    # current time plus a delay
            # The number of seconds in one day is 86400.
            $multiplier = isset($matches[2]) && $matches[2] === 'd' ? 86400 : 1;
            $qdate = Helper::getTemper(time() + $matches[1] * $multiplier);
        } elseif ($when === 'now') {    # a synonym for current time
            $qdate = Helper::getTemper(time());
        } elseif ($when === 'first') {
            # Lowest value first (lvf) requires $qdate of all zeroes.
            # To achieve "first" semantics, we use a $qdate of all
            # zeroes (default above), which means this key will be
            # selected even earlier than a key that became ripe in the
            # queue 85 days ago but wasn't selected because no one
            # minted anything in the last 85 days.
            #
            $seqnum = Db::$engine->get(Globals::_RR . "/fseqnum");
        #
            # NOTE: fseqnum is reset only when queue is empty; see mint().
            # If queue never empties fseqnum will simply keep growing,
            # so we effectively truncate on the left to 6 digits with mod
            # arithmetic when we convert it to $fixsqn via sprintf().
        } elseif ($when === 'delete') {
            $delete = 1;
        } elseif ($when !== 'lvf') {
            Log::addmsg($noid, sprintf(
                'error: unrecognized queue time: %s',
                $when
            ));
            return array();
        }

        if (!empty($qdate)) {     # current time plus optional delay
            if ($qdate > Db::$engine->get(Globals::_RR . "/gseqnum_date")) {
                $seqnum = Globals::SEQNUM_MIN;
                Db::$engine->set(Globals::_RR . "/gseqnum", $seqnum);
                Db::$engine->set(Globals::_RR . "/gseqnum_date", $qdate);
            } else {
                $seqnum = Db::$engine->get(Globals::_RR . "/gseqnum");
            }
        } else {
            $qdate = '00000000000000';  # this needs to be 14 zeroes
        }

        $iderrors = array();
        if (Db::$engine->get(Globals::_RR . "/genonly")) {
            $iderrors = self::validate($noid, '-', $ids);
            if (array_filter($iderrors, function ($v) {
                return stripos($v, 'error:') !== 0;
            })) {
                $iderrors = array();
            }
        }
        if ($iderrors) {
            Log::addmsg($noid, sprintf(
                'error: queue operation not started -- one or more ids did not validate: %s',
                PHP_EOL . implode(PHP_EOL, $iderrors)
            ));
            return array();
        }

        $firstpart = Db::$engine->get(Globals::_RR . "/firstpart");
        $padwidth = Db::$engine->get(Globals::_RR . "/padwidth");
        $currdate = Helper::getTemper();
        $retvals = array();
        $idval = null;
        $paddedid = null;
        $circ_svec = null;
        foreach ($ids as $id) {
            if (Db::$engine->exists("$id\t" . Globals::_RR . "/h")) {     # if there's a hold
                $m = sprintf(
                    'error: a hold has been set for "%s" and must be released before the identifier can be queued for minting.',
                    $id
                );
                Log::logmsg($noid, $m);
                $retvals[] = $m;
                continue;
            }

            # If there's no circulation record, it means that it was
            # queued to get it minted earlier or later than it would
            # normally be minted.  Log if term is "long".
            #
            $circ_svec = self::_get_circ_svec($noid, $id);

            if (substr($circ_svec, 0, 1) === 'q' && !$delete) {
                $m = sprintf(
                    'error: id %1$s cannot be queued since it appears to be in the queue already -- circ record is %2$s',
                    $id, Db::$engine->get("$id\t" . Globals::_RR . "/c")
                );
                Log::logmsg($noid, $m);
                $retvals[] = $m;
                continue;
            }
            if (substr($circ_svec, 0, 1) === 'u' && $delete) {
                $m = sprintf(
                    'error: id %1$s has been unqueued already -- circ record is %2$s',
                    $id, Db::$engine->get("$id\t" . Globals::_RR . "/c")
                );
                Log::logmsg($noid, $m);
                $retvals[] = $m;
                continue;
            }
            if (substr($circ_svec, 0, 1) !== 'q' && $delete) {
                $m = sprintf(
                    'error: id %1$s cannot be unqueued since its circ record does not indicate its being queued, circ record is %2$s',
                    $id, Db::$engine->get("$id\t" . Globals::_RR . "/c")
                );
                Log::logmsg($noid, $m);
                $retvals[] = $m;
                continue;
            }
            # If we get here and we're deleting, circ_svec must be 'q'.

            if ($circ_svec === '') {
                if (Db::getCached('longterm')) {
                    Log::logmsg($noid, sprintf(
                        'note: id %s being queued before first minting (to be pre-cycled)',
                        $id
                    ));
                }
            } elseif (substr($circ_svec, 0, 1) === 'i') {
                if (Db::getCached('longterm')) {
                    Log::logmsg($noid, sprintf(
                        'note: longterm id %s being queued for re-issue',
                        $id
                    ));
                }
            }

            # yyy ignore return OK?
            self::_set_circ_rec($noid, $id, ($delete ? 'u' : 'q') . $circ_svec, $currdate, $contact);

            $idval = preg_replace('/^' . preg_quote("$firstpart", '/') . '/', '', $id);
            $paddedid = sprintf("%0$padwidth" . "s", $idval);
            $fixsqn = sprintf("%06d", $seqnum % Globals::SEQNUM_MAX);

            Db::_dblock();

            Db::$engine->set(Globals::_RR . "/queued", Db::$engine->get(Globals::_RR . "/queued") + 1);
            if (Db::$engine->get(Globals::_RR . "/total") != Globals::NOLIMIT   # if total is non-zero
                && Db::$engine->get(Globals::_RR . "/queued") > Db::$engine->get(Globals::_RR . "/oatop")
            ) {
                Db::_dbunlock();

                $m = sprintf(
                    'error: queue count (%1$s) exceeding total possible on id %2$s.  Queue operation aborted.',
                    Db::$engine->get(Globals::_RR . "/queued"), $id
                );
                Log::logmsg($noid, $m);
                $retvals[] = $m;
                break;
            }
            Db::$engine->set(Globals::_RR . "/q/$qdate/$fixsqn/$paddedid", $id);

            Db::_dbunlock();

            if (Db::getCached('longterm')) {
                Log::logmsg($noid, sprintf(
                    'id: %1$s added to queue under %2$s',
                    Db::$engine->get(Globals::_RR . "/q/$qdate/$fixsqn/$paddedid"), Globals::_RR . "/q/$qdate/$seqnum/$paddedid"
                ));
            }
            $retvals[] = sprintf('id: %s', $id);
            if ($seqnum) {     # it's zero for "lvf" and "delete"
                $seqnum++;
            }
        }

        Db::_dblock();
        if ($when === 'first') {
            Db::$engine->set(Globals::_RR . "/fseqnum", $seqnum);
        } elseif ($qdate > 0) {
            Db::$engine->set(Globals::_RR . "/gseqnum", $seqnum);
        }
        Db::_dbunlock();

        return $retvals;
    }

    /**
     * Check that identifier matches a given template, where "-" means the
     * default template for this generator.  This is a complete check of all
     * characteristics _except_ whether the identifier is stored in the
     * database.
     *
     * Returns an array of strings that are messages corresponding to any ids
     * that were passed in.  Error strings # that pertain to identifiers
     * begin with "iderr: ".
     *
     * @param string       $noid
     * @param string       $template
     * @param array|string $ids
     *
     * @return array
     * @throws Exception
     */
    public static function validate($noid, $template, $ids)
    {
        // Db::$db_type should be set with dbopen(), dbcreate() or dbimport().
        self::init();

        $db = Db::getDb($noid);
        if (is_null($db)) {
            return null;
        }

        if (!is_array($ids)) {
            $ids = strlen($ids) == 0 ? array() : array($ids);
        }

        $first = null;
        $prefix = null;
        $mask = null;
        $gen_type = null;
        $msg = null;

        $retvals = array();

        if (empty($ids)) {
            Log::addmsg($noid, 'error: must specify a template and at least one identifier.');
            return array();
        }
        if (empty($template)) {
            # If $noid is null, the caller looks in Log::errmsg(null).
            Log::addmsg($noid, 'error: no template given to validate against.');
            return array();
        }

        $repertoire = null;

        if (!strcmp($template, '-')) {
            # $retvals[] = sprintf('template: %s', Globals::$db_engine->get(Globals::_RR."/template")));
            if (!Db::$engine->get(Globals::_RR . "/template")) {  # do blanket validation
                $nonulls = array_filter(preg_replace('/^(.)/', 'id: $1', $ids));
                if (empty($nonulls)) {
                    return array();
                }
                $retvals += $nonulls;
                return $retvals;
            }
            $prefix = Db::$engine->get(Globals::_RR . "/prefix");
            $mask = Db::$engine->get(Globals::_RR . "/mask");
            // Validate with the saved repertoire, if any.
            $repertoire = Db::$engine->get(Globals::_RR . "/addcheckchar")
                ? (Db::$engine->get(Globals::_RR . "/checkrepertoire") ? : Helper::getAlphabet($template))
                : '';
        } elseif (!Helper::parseTemplate($template, $prefix, $mask, $gen_type, $msg)) {
            Log::addmsg($noid, sprintf(
                'error: template %1$s bad: %2$s',
                $template, $msg
            ));
            return array();
        }

        $m = preg_replace('/k$/', '', $mask);
        $should_have_checkchar = $m !== $mask;
        if (is_null($repertoire)) {
            $repertoire = $should_have_checkchar ? Helper::getAlphabet($prefix . '.' . $mask) : '';
        }

        $naan = Db::$engine->get(Globals::_RR . "/naan");
        foreach ($ids as $id) {
            if (is_null($id) || trim($id) == '') {
                $retvals[] = "iderr: can't validate an empty identifier";
                continue;
            }

            # Automatically reject ids starting with Globals::_RR."/", unless it's an
            # "idmap", in which case automatically validate.  For an idmap,
            # the $id should be of the form ".Globals::_RR."/idmap/ElementName, with
            # element, Idpattern, and value, ReplacementPattern.
            #
            if (strpos(Globals::_RR . "/", $id) === 0) {
                $retvals[] = preg_match('|^' . preg_quote(Globals::_RR . "/idmap/", '|') . '.+|', $id)
                    ? sprintf('id: %s', $id)
                    : sprintf('iderr: identifiers must not start with "%s".', Globals::_RR . "/");
                continue;
            }

            $first = $naan;             # … if any
            if ($first) {
                $first .= '/';
            }
            $first .= $prefix;          # … if any
            $varpart = preg_replace('/^' . preg_quote($first, '/') . '/', '', $id);
            if (strlen($first) > 0 && strpos($id, $first) !== 0) {
                # yyy            ($varpart = $id) !~ s/^$prefix// and
                $retvals[] = sprintf('iderr: %s should begin with %s.', $id, $first);
                continue;
            }
            if ($should_have_checkchar && !Helper::checkChar($id, $repertoire)) {
                $retvals[] = sprintf('iderr: %s has a check character error', $id);
                continue;
            }
            ## xxx fix so that a length problem is reported before (or
            # in addition to) a check char problem

            # yyy needed?
            # if (strlen($first) + strlen($mask) - 1 != strlen($id)) {
            #     $retvals[] = sprintf(
            #         'error: %1$s has should have length %2$s',
            #         $id, (strlen($first) + strlen($mask) - 1)
            #     );
            #     continue;
            # }

            # Maskchar-by-Idchar checking.
            #
            $maskchars = str_split($mask);
            $mode = array_shift($maskchars);       # toss 'r', 's', or 'z'
            $suppl = $mode == 'z' ? $maskchars[0] : null;
            $flagBreakContinue = false;
            foreach (str_split($varpart) as $c) {
                // Avoid to str_split() an empty varpart.
                if (strlen($c) == 0) {
                    break;
                }
                $m = array_shift($maskchars);
                if (is_null($m)) {
                    if ($mode != 'z') {
                        $retvals[] = sprintf('iderr: %1$s longer than specified template (%2$s)', $id, $template);
                        $flagBreakContinue = true;
                        break;
                    }
                    $m = $suppl;
                }
                if (isset(Globals::$alphabets[$m]) && strpos(Globals::$alphabets[$m], $c) === false) {
                    $retvals[] = sprintf('iderr: %1$s char "%2$s" conflicts with template (%3$s) char "%4$s"%5$s',
                        $id, $c, $template, $m, $m == 'e' ? ' (extended digit)' : ($m == 'd' ? ' (digit)' : ''));
                    $flagBreakContinue = true;
                    break;
                }       # or $m === 'k', in which case skip
            }
            if ($flagBreakContinue) {
                continue;
            }

            $m = array_shift($maskchars);
            if (!is_null($m)) {
                $retvals[] = sprintf('iderr: %1$s shorter than specified template (%2$s)', $id, $template);
                continue;
            }

            # If we get here, the identifier checks out.
            $retvals[] = sprintf('id: %s', $id);
        }
        return $retvals;
    }

    /**
     * A hold may be placed on an identifier to keep it from being minted/issued.
     *
     * @param string       $noid
     * @param string       $contact
     * @param string       $on_off
     * @param array|string $ids
     *
     * @return int 0 (error) or 1 (success)
     * Sets errmsg() in either case.
     * @throws Exception
     */
    public static function hold($noid, $contact, $on_off, $ids)
    {
        // Db::$db_type should be set with dbopen(), dbcreate() or dbimport().
        self::init();

        $db = Db::getDb($noid);
        if (is_null($db)) {
            return 0;
        }

        if (!is_array($ids)) {
            $ids = strlen($ids) == 0 ? array() : array($ids);
        }

        # yyy what makes sense in this case?
        # if (! Globals::$db_engine->get(Globals::_RR."/template")) {
        #   Log::addmsg($noid,
        #       'error: holding makes no sense in a bind-only minter.'
        #   );
        #   return 0;
        # }
        if (empty($contact)) {
            Log::addmsg($noid, 'error: contact undefined');
            return 0;
        }
        if (empty($on_off)) {
            Log::addmsg($noid, 'error: hold "set" or "release"?');
            return 0;
        }
        if (empty($ids)) {
            Log::addmsg($noid, 'error: no id(s) specified');
            return 0;
        }
        if ($on_off !== 'set' && $on_off !== 'release') {
            Log::addmsg($noid, sprintf('error: unrecognized hold directive (%s)', $on_off));
            return 0;
        }

        $release = $on_off === 'release';
        # yyy what is sensible thing to do if no ids are present?
        $iderrors = array();
        if (Db::$engine->get(Globals::_RR . "/genonly")) {
            $iderrors = self::validate($noid, '-', $ids);
            if (array_filter($iderrors, function ($v) {
                return stripos($v, 'error:') !== 0;
            })) {
                $iderrors = array();
            }
        }
        if ($iderrors) {
            Log::addmsg($noid, sprintf(
                'error: hold operation not started -- one or more ids did not validate: %s',
                PHP_EOL . implode(PHP_EOL, $iderrors)
            ));
            return 0;
        }

        $status = null;
        $n = 0;
        foreach ($ids as $id) {
            if ($release) {     # no hold means key doesn't exist
                if (Db::getCached('longterm')) {
                    Log::logmsg($noid, sprintf('%1$s %2$s: releasing hold', Helper::getTemper(), $id));
                }
                Db::_dblock();
                $status = self::hold_release($noid, $id);
            } else {          # "hold" means key exists
                if (Db::getCached('longterm')) {
                    Log::logmsg($noid, sprintf('%1$s %2$s: placing hold', Helper::getTemper(), $id));
                }
                Db::_dblock();
                $status = self::hold_set($noid, $id);
            }
            Db::_dbunlock();
            if (!$status) {
                return 0;
            }
            $n++;           # xxx should report number

            # Incr/Decrement for each id rather than by count($ids);
            # if something goes wrong in the loop, we won't be way off.

            # XXX should we refuse to hold if "long" and issued?
            #     else we cannot use "hold" in the sense of either
            #     "reserved for future use" or "reserved, never issued"
            #
        }
        Log::addmsg($noid, $n == 1
            ? sprintf('Ok: 1 hold placed')
            : sprintf('Ok: %s holds placed', $n));
        return 1;
    }

    /**
     * Returns 1 on success, 0 on error.  Use dblock() before and dbunlock()
     * after calling this routine.
     *
     * @todo don't care if hold was in effect or not
     *
     * @param string $noid
     * @param string $id
     *
     * @return int 0 (error) or 1 (success)
     * @throws Exception
     */
    public static function hold_set($noid, $id)
    {
        // Db::$db_type should be set with dbopen(), dbcreate() or dbimport().
        self::init();

        $db = Db::getDb($noid);
        if (is_null($db)) {
            return 0;
        }

        Db::$engine->set("$id\t" . Globals::_RR . "/h", 1);        # value doesn't matter
        Db::$engine->set(Globals::_RR . "/held", Db::$engine->get(Globals::_RR . "/held") + 1);
        if (Db::$engine->get(Globals::_RR . "/total") != Globals::NOLIMIT   # ie, if total is non-zero
            && Db::$engine->get(Globals::_RR . "/held") > Db::$engine->get(Globals::_RR . "/oatop")
        ) {
            $m = sprintf(
                'error: hold count (%1$s) exceeding total possible on id %2$s',
                Db::$engine->get(Globals::_RR . "/held"), $id
            );
            Log::addmsg($noid, $m);
            Log::logmsg($noid, $m);
            return 0;
        }
        return 1;
    }

    /**
     * Returns 1 on success, 0 on error.  Use dblock() before and dbunlock()
     * after calling this routine.
     *
     * @todo don't care if hold was in effect or not
     *
     * @param string $noid
     * @param string $id
     *
     * @return int 0 (error) or 1 (success)
     * @throws Exception
     */
    public static function hold_release($noid, $id)
    {
        // Db::$db_type should be set with dbopen(), dbcreate() or dbimport().
        self::init();

        $db = Db::getDb($noid);
        if (is_null($db)) {
            return 0;
        }

        Db::$engine->delete("$id\t" . Globals::_RR . "/h");
        Db::$engine->set(Globals::_RR . "/held", Db::$engine->get(Globals::_RR . "/held") - 1);
        if (Db::$engine->get(Globals::_RR . "/held") < 0) {
            $m = sprintf(
                'error: hold count (%1$s) going negative on id %2$s',
                Db::$engine->get(Globals::_RR . "/held"), $id
            );
            Log::addmsg($noid, $m);
            Log::logmsg($noid, $m);
            return 0;
        }
        return 1;
    }

    /**
     * Identifier admin info is stored in three places:
     *
     *    id\t:/h    hold status: if exists = hold, else no hold
     *    id\t:/c    circulation record, if it exists, is
     *           circ_status_history_vector|when|contact(who)|oacounter
     *           where circ_status_history_vector is a string of [iqu]
     *           and oacounter is current overall counter value, FWIW;
     *           circ status goes first to make record easy to update
     *    id\t:/p    pepper
     *
     * @param string $noid
     * @param string $id
     *
     * @return string
     * Returns a single letter circulation status, which must be one
     * of 'i', 'q', or 'u'.  Returns the empty string on error.
     * @throws Exception
     */
    protected static function _get_circ_svec($noid, $id)
    {
        $db = Db::getDb($noid);
        if (is_null($db)) {
            return '';
        }

        $circ_rec = Db::$engine->get("$id\t" . Globals::_RR . "/c");
        if (empty($circ_rec)) {
            return '';
        }

        # Circulation status vector (string of letter codes) is the 1st
        # element, elements being separated by '|'.  We don't care about
        # the other elements for now because we can find everything we
        # need at the beginning of the string (without splitting it).
        # Let errors hit the log file rather than bothering the caller.
        #
        $circ_svec = explode('|', trim($circ_rec));
        $circ_svec = reset($circ_svec);

        if (empty($circ_svec)) {
            Log::logmsg($noid, sprintf(
                'error: id %1$s has no circ status vector -- circ record is %2$s',
                $id, $circ_rec
            ));
            return '';
        }
        if (!preg_match('/^([iqu])[iqu]*$/', $circ_svec, $matches)) {
            Log::logmsg($noid, sprintf(
                'error: id %1$s has a circ status vector containing letters other than "i", "q", or "u" -- circ record is %2$s',
                $id, $circ_rec
            ));
            return '';
        }
        return $matches[1];
    }

    /**
     * As a last step of issuing or queuing an identifier, adjust the circulation
     * status record.  We place a "hold" if we're both issuing an identifier and
     * the minter is for "long" term ids.  If we're issuing, we also purge any
     * element bindings that exist; this means that a queued identifier's bindings
     * will by default last until it is re-minted.
     *
     * The caller must know what they're doing because we don't check parameters
     * for errors; this routine is not externally visible anyway.  Returns the
     * input identifier on success, or null on error.
     *
     * @param string $noid
     * @param string $id
     * @param string $circ_svec
     * @param string $date
     * @param string $contact
     *
     * @return string|null
     * @throws Exception
     */
    protected static function _set_circ_rec($noid, $id, $circ_svec, $date, $contact)
    {
        $db = Db::getDb($noid);
        if (is_null($db)) {
            return null;
        }

        $status = 1;
        $circ_rec = "$circ_svec|$date|$contact|" . Db::$engine->get(Globals::_RR . "/oacounter");

        # yyy do we care what the previous circ record was?  since right now
        #     we just clobber without looking at it

        Db::_dblock();

        # Check for and clear any bindings if we're issuing an identifier.
        # We ignore the return value from _clear_bindings().
        # Replace or clear admin bindings by hand, including pepper if any.
        #       yyy pepper not implemented yet
        # If issuing a longterm id, we automatically place a hold on it.
        #
        if (strpos($circ_svec, 'i') === 0) {
            self::_clear_bindings($noid, $id, 0);
            Db::$engine->delete("$id\t" . Globals::_RR . "/p");
            if (Db::getCached('longterm')) {
                $status = Noid::hold_set($noid, $id);
            }
        }
        Db::$engine->set("$id\t" . Globals::_RR . "/c", $circ_rec);

        Db::_dbunlock();

        # This next logmsg should account for the bulk of the log when
        # longterm identifiers are in effect.
        #
        if (Db::getCached('longterm')) {
            Log::logmsg($noid, sprintf('m: %1$s%2$s', $circ_rec, $status ? '' : ' -- hold failed'));
        }

        if (empty($status)) {           # must be an error in hold_set()
            return null;
        }
        return $id;
    }

    /**
     * Returns an array of cleared ids and byte counts if $verbose is set,
     * otherwise returns an empty array.  Set $verbose when we want to report what
     * was cleared.  Admin bindings aren't touched; they must be cleared manually.
     *
     * We always check for bindings before issuing, because even a previously
     * unissued id may have been bound (unusual for many minter situations).
     *
     * Use dblock() before and dbunlock() after calling this routine.
     *
     * @param string $noid
     * @param string $id
     * @param string $verbose
     *
     * @return array|NULL
     * @throws Exception
     */
    protected static function _clear_bindings($noid, $id, $verbose)
    {
        $retvals = array();

        $db = Db::getDb($noid);
        if (is_null($db)) {
            return null;
        }

        # yyy right now "$id\t" defines how we bind stuff to an id, but in the
        #     future that could change.  in particular we don't bind (now)
        #     anything to just "$id" (without a tab after it)
        $first = "$id\t";
        $values = Db::$engine->get_range($first);
        if ($values) {
            foreach ($values as $key => $value) {
                $skip = preg_match('|^' . preg_quote("$first" . Globals::_RR . "/", '|') . '|', $key);
                if (!$skip && $verbose) {
                    # if $verbose (ie, fetch), include label and
                    # remember to strip "id\t" from front of $key
                    $key = preg_match('/^[^\t]*\t(.*)/', $key, $matches) ? $matches[1] : $key;
                    $retvals[] = $key . ': ' . sprintf('clearing %d bytes', strlen($value));
                    Db::$engine->delete($key);
                }
            }
        }
        return $verbose ? $retvals : array();
    }

    /**
     * Return $elem: $val or error string.
     *
     * @param string $noid
     * @param string $verbose
     * @param        $id
     * @param string $elem
     *
     * @return string
     * @throws Exception
     */
    protected static function _id2elemval($noid, $verbose, $id, $elem)
    {
        $db = Db::getDb($noid);
        if (is_null($db)) {
            return '';
        }

        $first = Globals::_RR . "/idmap/$elem\t";
        $values = Db::$engine->get_range($first);
        if (is_null($values)) {
            return sprintf('error: id2elemval: access to database failed.');
        }
        if (empty($values)) {
            return '';
        }
        $key = key($values);
        if (strpos($key, $first) !== 0) {
            return '';
        }
        foreach ($values as $key => $value) {
            $pattern = preg_match('|' . preg_quote($first, '|') . '(.+)|', $key) ? $key : null;
            $newval = $id;
            if (!empty($pattern)) {
                try {
                    # yyy kludgy use of unlikely delimiters (ascii 05: Enquiry)
                    $newval = preg_replace(chr(5) . preg_quote($pattern, chr(5)) . chr(5), $value, $newval);
                } catch (Exception $e) {
                    return sprintf('error: id2elemval eval: %s', $e->getMessage());
                }
                # replaced, so return
                return ($verbose ? $elem . ': ' : '') . $newval;
            }
        }
        return '';
    }

    /**
     * Pre-generate identifiers into a pool for instant retrieval.
     *
     * This fills a pre-generation pool with ready-to-use identifiers.
     * When mint() is called, it first checks this pool before generating
     * new IDs, providing near-instant response times.
     *
     * Example usage:
     * ```php
     * // Pre-generate 100 IDs into the pool
     * Noid::pregenerate($noid, 'contact@example.org', 100);
     *
     * // Later, mint() returns instantly from the pool
     * $id = Noid::mint($noid, 'contact@example.org');
     * ```
     *
     * @param string $noid    The noid/database path.
     * @param string $contact The contact responsible for the identifiers.
     * @param int    $count   Number of identifiers to pre-generate (max 10000).
     *
     * @return int Number of identifiers actually pre-generated.
     * @throws Exception
     */
    public static function pregenerate($noid, $contact, $count)
    {
        self::init();

        $db = Db::getDb($noid);
        if (is_null($db)) {
            return 0;
        }

        if (empty($contact)) {
            Log::addmsg($noid, 'contact undefined');
            return 0;
        }

        if ($count < 1) {
            Log::addmsg($noid, 'error: pregenerate count must be at least 1');
            return 0;
        }

        if ($count > 10000) {
            Log::addmsg($noid, 'error: pregenerate count cannot exceed 10000');
            return 0;
        }

        $template = Db::getCached('template');
        if (!$template) {
            Log::addmsg($noid, 'error: this minter does not generate identifiers.');
            return 0;
        }

        // Get current pool index (where to add new IDs)
        $poolIndex = (int) Db::$engine->get(Globals::_RR . "/pregen_tail");

        // Pre-compute values for batch generation
        $addcheckchar = Db::getCached('addcheckchar');
        $repertoire = $addcheckchar
            ? (Db::getCached('checkrepertoire') ?: Helper::getAlphabet($template))
            : '';
        $firstpart = Db::getCached('firstpart');
        $longterm = Db::getCached('longterm');
        $wrap = Db::getCached('wrap');

        if (Db::getCached('generator_type') == 'random') {
            self::$random_generator = Db::getCached('generator_random') ?: self::$random_generator;
            // Initialize PerlRandom if needed
            if (self::$random_generator == 'PerlRandom'
                || self::$random_generator == 'Perl_Random'
            ) {
                self::$_perlRandom = PerlRandom::init();
            }
        }

        $generated = 0;
        $currdate = Helper::getTemper();

        for ($i = 0; $i < $count; $i++) {
            // Seed random generator with current counter value
            srand((int) Db::$engine->get(Globals::_RR . "/oacounter"));

            // Generate an ID using the internal generator
            $id = Generator::_genid($noid);
            if ($id === null) {
                // Minter exhausted
                break;
            }

            // Apply check character if needed
            if ($addcheckchar) {
                $id = $firstpart . Helper::checkchar($id, $repertoire);
            } else {
                $id = $firstpart . $id;
            }

            // Store in pre-generation pool
            Db::_dblock();
            Db::$engine->set(Globals::_RR . "/p/$poolIndex", $id);
            Db::$engine->set(Globals::_RR . "/pregen_tail", $poolIndex + 1);
            Db::$engine->set(Globals::_RR . "/pregenerated",
                (int) Db::$engine->get(Globals::_RR . "/pregenerated") + 1);
            Db::_dbunlock();

            // Log the pre-generation
            $circ_svec = 'p';  // 'p' for pre-generated
            $circ_rec = "$circ_svec|$currdate|$contact|" . Db::$engine->get(Globals::_RR . "/oacounter");
            Db::$engine->set("$id\t" . Globals::_RR . "/c", $circ_rec);

            if ($longterm) {
                Log::logmsg($noid, sprintf('pregen: %s', $id));
            }

            $poolIndex++;
            $generated++;
        }

        return $generated;
    }

    /**
     * Get the count of pre-generated identifiers in the pool.
     *
     * @param string $noid The noid/database path.
     *
     * @return int Number of pre-generated IDs available.
     * @throws Exception
     */
    public static function getPregenCount($noid)
    {
        self::init();

        $db = Db::getDb($noid);
        if (is_null($db)) {
            return 0;
        }

        return (int) Db::$engine->get(Globals::_RR . "/pregenerated");
    }

    /**
     * Get an identifier from the pre-generation pool.
     *
     * @param string $noid    The noid/database path.
     * @param string $contact The contact for logging.
     *
     * @return string|null The identifier, or null if pool is empty.
     * @throws Exception
     */
    protected static function _getFromPregenPool($noid, $contact)
    {
        $pregenCount = (int) Db::$engine->get(Globals::_RR . "/pregenerated");
        if ($pregenCount <= 0) {
            return null;
        }

        $poolHead = (int) Db::$engine->get(Globals::_RR . "/pregen_head");
        $id = Db::$engine->get(Globals::_RR . "/p/$poolHead");

        if ($id === false || $id === null) {
            return null;
        }

        Db::_dblock();

        // Remove from pool
        Db::$engine->delete(Globals::_RR . "/p/$poolHead");
        Db::$engine->set(Globals::_RR . "/pregen_head", $poolHead + 1);
        Db::$engine->set(Globals::_RR . "/pregenerated", $pregenCount - 1);

        Db::_dbunlock();

        // Update circulation record from 'p' (pre-generated) to 'i' (issued)
        $currdate = Helper::getTemper();
        $circ_rec = Db::$engine->get("$id\t" . Globals::_RR . "/c");
        if ($circ_rec && substr($circ_rec, 0, 1) === 'p') {
            // Replace 'p' with 'i' for issued
            $circ_rec = 'i' . substr($circ_rec, 1);
            Db::$engine->set("$id\t" . Globals::_RR . "/c", $circ_rec);
        }

        if (Db::getCached('longterm')) {
            Log::logmsg($noid, sprintf('m: (from pregen pool) %s', $id));
        }

        return $id;
    }
}
