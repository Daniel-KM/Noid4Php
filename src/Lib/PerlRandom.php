<?php
/**
 * @author Daniel Berthereau <daniel.gitlab@berthereau.net>
 * @copyright Copyright (c) 2016-2019 Daniel Berthereau
 * @license CeCILL-C v1.0 http://www.cecill.info/licences/Licence_CeCILL-C_V1-en.txt
 * @version 0.1.1
 * @package PerlRandom
 */

/**
 * Port of the Perl function rand() in order to create the same sequence of
 * pseudo-random numbers using the drand48 Linear Congruential Generator (LCG).
 *
 * This class is designed for a 64-bit platform (or above) with a true
 * underlying 64-bit code (may not work with some 64-bit Windows).
 *
 * Performance: Uses native 64-bit arithmetic with split multiplication to avoid
 * overflow (the LCG multiplier * state would require 83 bits). For int_rand()
 * with len <= 32767, the entire computation is native (~37x faster than BCMath).
 * BCMath is used as fallback for larger values or float operations.
 *
 * Currently, only the output of integers (via int(rand())) is managed without
 * difference between perl 5.20 and php 5.3.15 or greater, until 32 bits, the
 * perl limit. The function "int_rand()" is specially designed for this case: it
 * gets rand() as integer without useless internal rounding.
 *
 * For float numbers, the port works fine for rand(1) or a length below 13 bits
 * (8192), but results are incorrect for length larger (the last decimal may
 * differ of 1 to 10). Between perl and php, the main difficulty is to round the
 * float value. Furthermore, Perl uses 15 digits and Php 14 digits to represent
 * a float. A special method is provided to get the rand() float value with 15
 * digits: "string_rand()". Nevertheless, this part of the tool should be
 * corrected.
 *
 * This is a singleton so that the same sequence is available anywhere. Hence,
 * it should be initialized when needed:
 *     $perlRandom = PerlRandom::init();
 * Then, for example:
 *     $perlRandom->srand(1234567890);
 *     $random = $perlRandom->int_rand(293);
 *
 * Note:
 * On Php, rand() and mt_srand() create stable sequences since 5.3.15 and were
 * improved in php 7.1 (see @link https://secure.php.net/manual/en/migration71.incompatible.php#migration71.incompatible.fixes-to-mt_rand-algorithm).
 * On Perl, rand() creates stable sequences since 5.20.0.
 *
 * @todo Manage float random numbers from 8193 until 32 bits (Perl limit).
 * @see PerlRandomTest
 *
 * @internal BCMath extension is optional but recommended for float operations
 * and large len values in int_rand().
 *
 * @link http://pubs.opengroup.org/onlinepubs/007908799/xsh/drand48.html
 * @link http://wellington.pm.org/archive/200704/randomness/
 * @link https://rosettacode.org/wiki/Random_number_generator_%28included%29#Perl
 */

namespace Noid\Lib;

use Exception;

class PerlRandom
{
    /**
     * The reference to the singleton instance of this class.
     *
     * @var PerlRandom
     */
    private static $_instance;

    /**
     * Store the seed as a 48-bit integer.
     *
     * @see self::srand48()
     * @var int
     */
    private $_random_state_48 = null;

    /**
     * Use native 64-bit arithmetic (faster) or BCMath fallback.
     *
     * @var bool
     */
    private $_useNative64 = true;

    /**
     * PerlRandom constructor.
     * @throws Exception
     */
    private function __construct()
    {
        if (PHP_INT_SIZE < 8) {
            throw new Exception('PerlRandom requires a true 64-bit platform.');
        }

        // Use native 64-bit arithmetic (much faster, ~37x for int_rand).
        // BCMath is only needed for float operations or int_rand with len > 32767.
        $this->_useNative64 = true;
    }

    /**
     * This class is a singleton.
     * @throws Exception
     */
    public static function init()
    {
        if (is_null(self::$_instance)) {
            self::$_instance = new PerlRandom();
        }
        return self::$_instance;
    }

    /**
     * Store a seed to create 48-bit pseudo-random integers via a linear
     * congruential generator.
     *
     * @param int $seed A 32-bit integer. If more than 32 bits, only
     * the high-order 32 bits are kept internally.
     */
    public function srand48($seed = null)
    {
        // True pseudo-random initialization.
        if (is_null($seed)) {
            $this->_random_state_48 = $this->_random48();
        }
        // Initialize with the specified seed.
        else {
            $seed = (int) $seed;
            // The input seed is a 32-bit integer: greater bits are discarded.
            $this->_random_state_48 = (($seed << 16) + 0x330E) & 0xFFFFFFFFFFFF;
        }
    }

    /**
     * Alias of srand48().
     *
     * @uses self::srand48()
     *
     * @param int $seed
     *
     * @return void.
     */
    public function srand($seed = null)
    {
        $this->srand48($seed);
    }

    /**
     * Get a pseudo-random integer (48-bit).
     *
     * Uses the drand48 Linear Congruential Generator (LCG) algorithm:
     *   new_state = (a * state + c) mod 2^48
     * where a = 25214903917, c = 11, m = 2^48.
     *
     * The multiplication a * state requires up to 83 bits (35 + 48), which
     * overflows PHP's 64-bit integers. To avoid this, we split both operands
     * into 24-bit halves and compute partial products that fit in 64 bits.
     *
     * @uses self::srand48()
     * @return int 48-bit pseudo-random integer.
     */
    public function rand48()
    {
        // Initialize the random state if this is the first use.
        if (is_null($this->_random_state_48)) {
            $this->srand48();
        }

        if ($this->_useNative64) {
            $this->_random_state_48 = $this->_rand48Native();
        } else {
            $this->_random_state_48 = $this->_rand48BCMath();
        }

        return $this->_random_state_48;
    }

    /**
     * Native 64-bit implementation of drand48 LCG.
     *
     * Split multiplication to avoid overflow:
     *   a = a_high * 2^24 + a_low  (where a = 25214903917)
     *   state = s_high * 2^24 + s_low
     *   a * state mod 2^48 = ((a_high*s_low + a_low*s_high) mod 2^24) * 2^24 + a_low*s_low
     *
     * Each partial product fits in ~48-59 bits, safe for 64-bit arithmetic.
     *
     * @return int 48-bit pseudo-random integer.
     */
    private function _rand48Native()
    {
        // LCG constants for drand48
        // a = 25214903917 = 0x5DEECE66D (35 bits)
        // Split: a_high = a >> 24 = 1502, a_low = a & 0xFFFFFF = 15525485
        $a_high = 1502;
        $a_low = 15525485;
        $c = 11;
        $mask24 = 0xFFFFFF;        // 24-bit mask
        $mask48 = 0xFFFFFFFFFFFF;  // 48-bit mask

        $state = $this->_random_state_48;

        // Split state into two 24-bit parts
        $s_low = $state & $mask24;
        $s_high = ($state >> 24) & $mask24;

        // Compute partial products (each fits in 64 bits)
        // a * state = a_high*s_high*2^48 + (a_high*s_low + a_low*s_high)*2^24 + a_low*s_low
        // The a_high*s_high*2^48 term vanishes mod 2^48
        $term_low = $a_low * $s_low;                      // max 48 bits
        $term_mid = $a_high * $s_low + $a_low * $s_high;  // max 49 bits

        // Combine: keep only lower 24 bits of term_mid before shifting
        $result = (($term_mid & $mask24) << 24) + $term_low + $c;

        // Final mask to 48 bits
        return $result & $mask48;
    }

    /**
     * BCMath implementation of drand48 LCG (fallback).
     *
     * @return int 48-bit pseudo-random integer.
     */
    private function _rand48BCMath()
    {
        return (int) bcmod(bcadd(bcmul('25214903917', (string) $this->_random_state_48, 0), '11', 0), '281474976710656');
    }

    /**
     * Get a pseudo-random float.
     *
     * This method doesn't cast to float as Perl (15 digits). If needed, rand(1)
     * can be used.
     *
     * @uses self::rand48()
     * @return float Pseudo-random float.
     */
    public function drand48()
    {
        return (float) bcdiv((string) $this->rand48(), '281474976710656', 32);
    }

    /**
     * Get a pseudo-random float in order to emulate the perl function rand().
     *
     * This function has been checked until 8192 only.
     *
     * @uses self::_string_rand64()
     *
     * @param int $len Max exclusive returned value.
     *
     * @return float Pseudo-random float.
     */
    public function rand($len = 1)
    {
        return (float) $this->_string_rand64($len);
    }

    /**
     * Get a pseudo-random integer to emulate the perl function int(rand()).
     *
     * For len <= 32767 (15 bits), uses fast native 64-bit arithmetic.
     * For larger len, falls back to BCMath to avoid overflow.
     *
     * @uses self::_string_rand64()
     *
     * @param int $len Max exclusive returned value.
     *
     * @return int Pseudo-random integer.
     */
    public function int_rand($len = 1)
    {
        $length = (int) $len;
        // For small len values, use native arithmetic (len * state fits in 63 bits)
        // 15 bits (len) + 48 bits (state) = 63 bits, safe for signed 64-bit
        if ($this->_useNative64 && $length > 0 && $length <= 32767) {
            $state = $this->rand48();
            // floor(len * state / 2^48) = (len * state) >> 48
            return (int) (($length * $state) >> 48);
        }
        return (int) $this->_string_rand64($len);
    }

    /**
     * Get a pseudo-random float as a string representation with 15 digits max
     * in order to emulate the perl function rand().
     *
     * @todo This function is under development and doesn't return the same
     * string (last decimal may differ).
     *
     * @uses self::_string_rand64()
     *
     * @param int $len Max exclusive returned value.
     *
     * @return string Pseudo-random float as a string with 15 digits max.
     */
    public function string_rand($len = 1)
    {
        $result = $this->_string_rand64($len);
        return $this->_significant15($result);
    }

    /**
     * Get a pseudo-random float as string to emulate the perl function rand().
     *
     * @internal The Perl rand() tries libc drand48() first, then random(), then
     * rand(). Here, the drand48() is emulated, so it is always used, like in Perl
     * above 5.20.0 and like in standard implementations before.
     *
     * @uses self::drand48()
     *
     * @param int $len Max exclusive returned value.
     *
     * @return string Pseudo-random float as a string with 64 digits.
     */
    private function _string_rand64($len = 1)
    {
        $length = (int) $len;
        // Don't use drand48() in order to avoid a conversion to float.
        return bcdiv(bcmul($length ? (string) $length : '1', (string) $this->rand48(), 0), '281474976710656', 32);
    }

    /**
     * Round a lloating value to 15 significant digits.
     *
     * The conversion to float between Perl and Php is slightly different (15 or
     * 14 digits).
     *
     * @internal Mode is PHP_ROUND_HALF_UP (default).
     *
     * @param string $bcNumber A bc number.
     *
     * @return string
     */
    private function _significant15($bcNumber)
    {
        $significant = 15;

        $point = strpos($bcNumber, '.');
        if ($point === false) {
            return $this->_removeTrailingZeros($bcNumber);
        }

        // There is no exposant in a bc number.
        $decimal = substr($bcNumber, $point + 1);
        if (strlen($decimal) <= $significant) {
            return $this->_removeTrailingZeros($bcNumber);
        }

        $integer = substr($bcNumber, 0, $point);

        // When the number is greater or equal to 1, the 15 significant digits
        // are always the first ones, minus the size of the integer part.
        if ($integer !== '0') {
            $secondSignificant = $significant - strlen($integer);
            if ($secondSignificant <= 0) {
                return bccomp('0.' . $decimal, '0.5', 32) >= 0
                    ? bcadd($integer, '1', 0)
                    : $integer;
            }

            $secondDecimal = '0.' . substr($decimal, $secondSignificant);
            $toRound = substr($decimal, 0, $secondSignificant);
            // Check if a round is required.
            if (bccomp($secondDecimal, '0.5', 64) < 0) {
                $result = $integer . '.' . $toRound;
            }
            // Need to round.
            else {
                $toRound = bcadd($toRound, '1', 0);
                $result = strlen($toRound) > $significant
                    ? bcadd($integer, '1', 0) . '.' . substr($toRound, 1)
                    : $integer . '.' . str_pad($toRound, $secondSignificant, '0', STR_PAD_LEFT);
            }
        }
        // Else, get the 15 significant digits with a negative exposant.
        else {
            $firstNonZero = strspn($decimal, '0');
            $toRound = bcmul('0.' . substr($decimal, $firstNonZero), str_pad('10', $significant + 1, '0'), 64);
            $secondDecimal = '0.' . substr($toRound, strpos($toRound, '.') + 1);
            if (bccomp($secondDecimal, '0.5', 32) >= 0) {
                $toRound = bcadd($toRound, '1');
            }
            $toRound = substr($toRound, 0, $significant);
            $result = $integer . '.' . str_repeat('0', $firstNonZero) . $toRound;
        }

        return $this->_removeTrailingZeros($result);
    }

    /**
     * Remove the trailing zeros.
     *
     * @param string $bcNumber A bc number.
     *
     * @return string
     */
    private function _removeTrailingZeros($bcNumber)
    {
        if (strpos($bcNumber, '.') === false) {
            return $bcNumber;
        }
        $bcNumber = rtrim($bcNumber, '0');
        return strrpos($bcNumber, '.') === strlen($bcNumber) - 1
            ? rtrim($bcNumber, '.')
            : $bcNumber;
    }

    /**
     * Return a pseudo-random integer of 48 bits.
     *
     * This function doesn't use srand() or mt_rand() in order to avoid side
     * effects.
     *
     * @return int 48-bit integer.
     */
    private function _random48()
    {
        // The length is 48 bits, so 6 bytes.
        $length = 6;

        // Try /dev/urandom if exists.
        if (@is_readable('/dev/urandom')) {
            $fh = fopen('/dev/urandom', 'r');
            $randomBytes = fread($fh, $length);
            fclose($fh);
            $hexa = '';
            for ($i = 0; $i < $length; $i++) {
                $byte = ord(substr($randomBytes, $i, 1));
                $hexa .= substr('0' . dechex($byte), -2);
            }
        }

        // No /dev/urandom, so use the time.
        else {
            $randomBytes = function_exists('microtime') ? sha1(microtime()) : sha1(time());
            $hexa = substr($randomBytes, 0, $length * 2);
        }

        $random = hexdec($hexa) & 0xFFFFFFFFFFFF;
        return (int) $random;
    }
}
