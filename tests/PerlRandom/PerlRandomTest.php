<?php
/**
 * @author Daniel Berthereau <daniel.gitlab@berthereau.net>
 * @copyright Copyright (c) 2016-2019 Daniel Berthereau
 * @license CeCILL-C v1.0 http://www.cecill.info/licences/Licence_CeCILL-C_V1-en.txt
 * @version 0.1.1
 * @package PerlRandom
 */

use PHPUnit\Framework\TestCase;
use Noid\Lib\PerlRandom;

/**
 * Tests for PerlRandom.
 *
 * WARNING: It’s normal that the test fails for testRand() and testStringRand():
 * perl and php are not compatible with seed above 8192 for float numbers. It
 * means you should not use such params when you need the same series for perl
 * and php.
 */
class PerlRandomTest extends TestCase
{
    public function setUp()
    {
        // Check if perl is available.
        $cmd = 'perl -v';
        $result = shell_exec($cmd);
        if (empty($result)) {
            $this->markTestSkipped(
                'Perl is unavailable.'
            );
        }
    }

    /**
     * Compare the perl int(rand()) and the PerlRandom->int_rand().
     * @throws Exception
     */
    public function testIntRand()
    {
        $perlRandom = PerlRandom::init();
        $maxLength = pow(2, 32);
        $length = 1;
        $loop = 0;
        while ($length <= $maxLength) {
            $seed = rand(0, 4294967295);
            $perl = $this->_perlIntRandSeed($length, $seed);
            $perlRandom->srand($seed);
            $php = $perlRandom->int_rand($length);
            $this->assertEquals($perl, $php,
                sprintf('Perl rand() "%s" is not equal to PerlRandom "%s" [seed: %d, length: %d, loop: %d]',
                    $perl, $php, $seed, $length, $loop));
            ++$length;
            if ((++$loop % 1000) == 0) {
                fwrite(STDERR, sprintf('%s: Seed: %d - Length: %d - Loop: %d / 136000' . PHP_EOL, __FUNCTION__, $seed, $length, $loop));
                $length = intval($length * 1.1);
            }
        }
    }

    /**
     * Compare the perl rand(1) and the PerlRandom->rand(1).
     *
     * @internal The representation of the value may be different, but the real
     * internal value remains the same.
     * @throws Exception
     */
    public function testRand1()
    {
        $this->_rand(1, 'testRand1');
    }

    /**
     * Compare the perl rand(8192) and the PerlRandom->rand(8192).
     * @throws Exception
     */
    public function testRand8192()
    {
        $this->_rand(8192, 'testRand8192');
    }

    /**
     * @internal The representation of the value may be different for the last
     * digital, but the php internal value remains the same.
     * @throws Exception
     */
    protected function _rand($length, $functionName)
    {
        $perlRandom = PerlRandom::init();
        $maxLength = pow(2, 32);
        $seed = 0;
        $loop = 0;
        while ($seed <= $maxLength) {
            $perl = $this->_perlRandSeed($length, $seed);
            $perlRandom->srand($seed);
            $php = $perlRandom->rand($length);
            $this->assertEquals($perl, $php,
                sprintf('Perl rand() "%s" is not equal to PerlRandom "%s" [seed: %d, length: %d, loop: %d]',
                    $perl, $php, $seed, $length, $loop));
            ++$seed;
            if ((++$loop % 1000) == 0) {
                fwrite(STDERR, sprintf('%s: Seed: %d - Length: %d - Loop: %d / 136000' . PHP_EOL, $functionName, $seed, $length, $loop));
                $seed = intval($seed * 1.1);
            }
        }
    }

    /**
     * Compare the perl rand() and the PerlRandom->rand().
     * @throws Exception
     */
    public function testRand()
    {
        $perlRandom = PerlRandom::init();
        $maxLength = pow(2, 32);
        $length = 1;
        $loop = 0;
        while ($length <= $maxLength) {
            $seed = rand(0, 4294967295);
            $perl = $this->_perlRandSeed($length, $seed);
            $perlRandom->srand($seed);
            $php = $perlRandom->rand($length);
            $this->assertEquals($perl, $php,
                sprintf('Perl rand() "%s" is not equal to PerlRandom "%s" [seed: %d, length: %d, loop: %d]',
                    $perl, $php, $seed, $length, $loop));
            ++$length;
            if ((++$loop % 1000) == 0) {
                fwrite(STDERR, sprintf('%s: Seed: %d - Length: %d - Loop: %d / 136000' . PHP_EOL, __FUNCTION__, $seed, $length, $loop));
                $length = intval($length * 1.1);
            }
        }
    }

    /**
     * Compare the perl rand() and the PerlRandom->string_rand().
     * @throws Exception
     */
    public function testStringRand()
    {
        $perlRandom = PerlRandom::init();
        $maxLength = pow(2, 32);
        $length = 1;
        $loop = 0;
        while ($length <= $maxLength) {
            $seed = rand(0, 4294967295);
            $perl = $this->_perlRandSeed($length, $seed);
            $perlRandom->srand($seed);
            $php = $perlRandom->string_rand($length);
            $this->assertEquals($perl, $php,
                sprintf('Perl rand() "%s" is not equal to PerlRandom "%s" [seed: %d, length: %d, loop: %d]',
                    $perl, $php, $seed, $length, $loop));
            ++$length;
            if ((++$loop % 1000) == 0) {
                fwrite(STDERR, sprintf('%s: Seed: %d - Length: %d - Loop: %d / 136000' . PHP_EOL, __FUNCTION__, $seed, $length, $loop));
                $length = intval($length * 1.1);
            }
        }
    }

    /**
     * Return a random float via the perl srand() and rand().
     *
     * This process uses perl via an external command.
     *
     * @param int $length The max value returned, exclusive.
     * @param int $seed
     * @return int|null Null in case of error.
     */
    protected function _perlRandSeed($length, $seed)
    {
        $cmd = 'perl -e "srand(' . $seed . '); print rand(' . $length . ');"';
        $result = exec($cmd);
        return exec($cmd);
    }

    /**
     * Return a random integer via the perl srand() and rand().
     *
     * This process uses perl via an external command.
     *
     * @param int $length The max value returned, exclusive.
     * @param int $seed
     * @return int|null Null in case of error.
     */
    protected function _perlIntRandSeed($length, $seed)
    {
        $cmd = 'perl -e "srand(' . $seed . '); print int(rand(' . $length . '));"';
        $result = exec($cmd);
        return $result;
    }
}
