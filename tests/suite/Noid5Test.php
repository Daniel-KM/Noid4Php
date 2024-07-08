<?php
/**
 * @author Michael A. Russell
 * @author Daniel Berthereau (conversion to Php)
 * @package Noid
 */

namespace NoidTest;

use Exception;

/**
 * Tests for Noid (5).
 *
 * ------------------------------------
 *
 * Project: Noid
 *
 * Name: noid5.t
 *
 * Function: To test the noid command.
 *
 * What Is Tested:
 * - Create minter with template de, for 290 identifiers.
 * - Try to bind to the 3rd identifier that would be minted, and check that it
 *   failed.
 *
 * Command line parameters:  none.
 *
 * Author: Michael A. Russell
 *
 * Revision History:
 * 07/19/2004 - MAR - Initial writing
 *
 * ------------------------------------
 *
 * @covers \Noid\Lib\Db
 */
class Noid5Test extends NoidTestCase
{
    const dbtype = 'lmdb';

    /**
     * @throws Exception
     */
    public function testNoid5()
    {
        $noid_cmd = $this->cmd . ' -f ' . escapeshellarg($this->settings_file) . ' ' . ' -t ' . self::dbtype . ' ';
        # Start off by doing a dbcreate.
        # First, though, make sure that the BerkeleyDB files do not exist.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst5.rde long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Check that the "NOID" subdirectory was created.
        $this->assertFileExists($this->noid_dir, 'No minter directory created, stopped');
        # echo 'NOID was created';

        # That "NOID" is a directory.
        $this->assertTrue(is_dir($this->noid_dir), 'NOID is not a directory, stopped');
        # echo 'NOID is a directory';

        # Check for the presence of the "README" file, then "log" file, then the
        # "loglmdb" file within "NOID".
        $this->assertFileExists($this->noid_dir . 'README');
        # echo 'NOID/README was created';
        $this->assertFileExists($this->noid_dir . 'log');
        # echo 'NOID/log was created';
        $this->assertFileExists($this->noid_dir . 'loglmdb');
        # echo 'NOID/loglmdb was created';

        # Check for the presence of the BerkeleyDB file within "NOID".
        $this->assertFileExists($this->noid_dir . 'noid.lmdb', 'Minter initialization failed, stopped');
        # echo 'NOID/noid.lmdb was created';

        # Try binding the 3rd identifier to be minted.
        $cmd = "{$noid_cmd} bind set 13030/tst594 element value 2>&1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        $noid_output = explode(PHP_EOL, $output);
        $noid_output = array_map('trim', $noid_output);
        $noid_output = array_filter($noid_output, 'strlen');
        $this->assertGreaterThanOrEqual(1, count($noid_output));
        # echo 'at least one line of output from attempt to bind to an unminted id';

        $msg = 'error: 13030/tst594: "long" term disallows binding an unissued identifier unless a hold is first placed on it.';
        $this->assertEquals($msg, $noid_output[0]);
        # echo 'disallowed binding to unminted id';
    }
}
