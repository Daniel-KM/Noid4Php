<?php
/**
 * @author Michael A. Russell
 * @author Daniel Berthereau (conversion to Php)
 * @package Noid
 */

namespace NoidTest;

use Exception;

/**
 * Tests for Noid (2).
 *
 * ------------------------------------
 *
 * Project: Noid
 *
 * Name: noid2.t
 *
 * Function: To test the noid command.
 *
 * What Is Tested:
 * - Create a minter.
 * - Queue something.
 * - Check that it was logged properly.
 *
 * Command line parameters:  none.
 *
 * Author: Michael A. Russell
 *
 * Revision History:
 * 07/19/2004 - MAR - Initial writing
 *
 * ------------------------------------
 */
class Noid2Test extends NoidTestCase
{
    const dbtype = 'lmdb';

    /**
     * @throws Exception
     */
    public function testNoid2()
    {
        $noid_cmd = $this->cmd . ' -f ' . escapeshellarg($this->settings_file) . ' ' . ' -t ' . self::dbtype . ' ';
        # Start off by doing a dbcreate.
        # First, though, make sure that the BerkeleyDB files do not exist.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst2.rde long 13030 cdlib.org noidTest >/dev/null";
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

        # Try to queue one.
        $cmd = "{$noid_cmd} queue now 13030/tst27h >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Examine the contents of the log.
        $fh = fopen($this->noid_dir . 'log', 'r');
        $this->assertNotEmpty($fh, 'failed to open log file, stopped');
        # echo 'successfully opened "' . $this->noid_dir . 'log"';

        # Read in the log.
        fclose($fh);
        $log_lines = file($this->noid_dir . 'log');

        $this->assertEquals(4, count($log_lines),
            # If we don't have exactly 4 lines, something is probably very wrong.
            'log_lines: ' . implode(', ', $log_lines));
        # echo 'number of lines in "' . $this->noid_dir . 'log"';

        # Remove trailing newlines.
        $log_lines = array_map('trim', $log_lines);

        # Check the contents of the lines.
        $this->assertEquals('Creating database for template "tst2.rde".', $log_lines[0]);
        # echo 'line 1 of "' . $this->noid_dir . 'log" correct';
        $this->assertEquals('note: id 13030/tst27h being queued before first minting (to be pre-cycled)', $log_lines[1]);
        # echo 'line 2 of "' . $this->noid_dir . 'log" correct';
        # The "()" are allowed for posix name.
        $regex = preg_quote('m: q|', '@') . '\d\d\d\d\d\d\d\d\d\d\d\d\d\d' . preg_quote('|', '@') . '[a-zA-Z0-9_-]*/[a-zA-Z0-9_-]*(?: \([a-zA-Z0-9_-]*/[a-zA-Z0-9_-]*\))?' . preg_quote('|0', '@');
        $this->assertTrue((bool) preg_match('@' . $regex . '@', $log_lines[2]));
        # echo 'line 3 of "' . $this->noid_dir . 'log" correct';
        $this->assertTrue((bool) preg_match('/^id: 13030\/tst27h added to queue under :\/q\//', $log_lines[3]));
        # echo 'line 4 of "' . $this->noid_dir . 'log" correct';
    }
}
