<?php
/**
 * @author Michael A. Russell
 * @author Daniel Berthereau (conversion to Php)
 * @package Noid
 */

namespace NoidTest;

use Exception;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'NoidTestCase.php';

class NoidSqliteTest extends NoidTestCase
{
    const dbtype = 'sqlite';

    /**
     * Tests for Noid (1).
     *
     * ------------------------------------
     *
     * Project: Noid
     *
     * Name: noid1.t
     *
     * Function: To test the noid command.
     *
     * What Is Tested:
     * - Create minter with template de, for 290 identifiers.
     * - Mint 288.
     * - Mint 1 and check that it was what was expected.
     * - Queue one of the 288 and check that it failed.
     * - Release hold on 3 of the 288.
     * - Queue those 3.
     * - Mint 3 and check that they are the ones that were queued.
     * - Mint 1 and check that it was what was expected.
     * - Mint 1 and check that it failed.
     *
     * Command line parameters:  none.
     *
     * Author: Michael A. Russell
     *
     * Revision History:
     * 07/15/2004 - MAR - Initial writing
     *
     * ------------------------------------
     * @throws Exception
     */
    public function testNoidSqlite1()
    {
        $noid_cmd = $this->cmd . ' -f ' . $this->dir . ' ' . ' -t ' . self::dbtype . ' ';
        # Start off by doing a dbcreate.
        # First, though, make sure that the BerkeleyDB files do not exist.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst1.rde long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Check that the "NOID" subdirectory was created.
        $this->assertFileExists($this->noid_dir, 'no minter directory created, stopped');
        # echo 'NOID was created';

        # That "NOID" is a directory.
        $this->assertTrue(is_dir($this->noid_dir), 'NOID is not a directory, stopped');
        # echo 'NOID is a directory';

        # Check for the presence of the "README" file, then "log" file, then the
        # "logbdb" file within "NOID".
        $this->assertFileExists($this->noid_dir . 'README');
        # echo 'NOID/README was created';
        $this->assertFileExists($this->noid_dir . 'log');
        # echo 'NOID/log was created';

        # Check for the presence of the BerkeleyDB file within "NOID".
        $this->assertFileExists($this->noid_dir . 'noid.sqlite', 'minter initialization failed, stopped');
        # echo 'NOID/noid.bdb was created';

        # Mint all but the last two of 290.
        $cmd = "{$noid_cmd} mint 288";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Clean up each output line.
        $noid_output = explode(PHP_EOL, $output);
        foreach ($noid_output as &$no) {
            $no = trim($no);
            $no = preg_replace('/^\s*id:\s+/', '', $no);
        }
        # If the last one is the null string, delete it.
        $noid_output = array_filter($noid_output, 'strlen');
        # We expect to have 288 entries.
        $this->assertEquals(288, count($noid_output));
        # echo 'number of minted noids is 288';

        # Save number 20, number 55, and number 155.
        $save_noid[0] = $noid_output[20];
        $save_noid[1] = $noid_output[55];
        $save_noid[2] = $noid_output[155];
        unset($noid_output);

        # Mint the next to last one.
        $cmd = "{$noid_cmd} mint 1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);
        # Remove leading "id: ".
        $noid = preg_replace('/^id:\s+/', '', $output);
        $this->assertNotEmpty($noid);
        # echo '"id: " precedes output of mint command for next to last noid';
        # Remove trailing white space.
        $noid = preg_replace('/\s+$/', '', $noid);
        $this->assertNotEmpty($noid);
        # echo "white space follows output of mint command for next to last noid");
        # This was the next to the last one on 7/16/2004.
        #is($noid, "13030/tst11q", "next to last noid was \"13030/tst11q\"");
        $this->assertEquals('13030/tst190', $noid);
        # echo 'next to last noid was "13030/tst190"';

        # Try to queue one of the 3.  It shouldn't let me, because the hold must
        # be released first.
        $cmd = "{$noid_cmd} queue now $save_noid[0] 2>&1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Verify that it won't let me.
        $noidOutput0 = trim($output);
        $noidOutput0 = preg_match('/^error: a hold has been set for .* and must be released before the identifier can be queued for minting/', $noidOutput0);
        $this->assertNotEmpty($noidOutput0);
        # echo 'correctly disallowed queue before hold release';

        # Release the hold on the 3 minted noids.
        $cmd = "{$noid_cmd} hold release $save_noid[0] $save_noid[1] $save_noid[2] > /dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Queue those 3.
        $cmd = "{$noid_cmd} queue now $save_noid[0] $save_noid[1] $save_noid[2] > /dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Mint them.
        $cmd = "{$noid_cmd} mint 3";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Clean up each line.
        $noid_output = explode(PHP_EOL, $output);
        foreach ($noid_output as &$no) {
            $no = trim($no);
            $no = preg_replace('/^\s*id:\s+/', '', $no);
        }
        # If the last one is the null string, delete it.
        $noid_output = array_filter($noid_output, 'strlen');
        # We expect to have 3 entries.
        $this->assertEquals(3, count($noid_output));
        # echo '(minted 3 queued noids) number of minted noids is 3';

        # Check their values.
        $this->assertEquals($save_noid[0], $noid_output[0]);
        # echo 'first of three queued & reminted noids';
        $this->assertEquals($save_noid[1], $noid_output[1]);
        # echo 'second of three queued & reminted noids';
        $this->assertEquals($save_noid[2], $noid_output[2]);
        # echo 'third of three queued & reminted noids';
        unset($save_noid);
        unset($noid_output);

        # Mint the last one.
        $cmd = "{$noid_cmd} mint 1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);
        # Remove leading "id: ".
        $noid = preg_replace('/^id:\s+/', '', $output);
        $this->assertNotEmpty($noid);
        # echo '"id: " precedes output of mint command for last noid';
        # Remove trailing white space.
        $noid = preg_replace('/\s+$/', '', $noid);
        $this->assertNotEmpty($noid);
        # echo "white space follows output of mint command for next to last noid");
        # This was the the last one on 7/16/2004.
        #is($noid, "13030/tst10f", "last noid was \"13030/tst10f\"");
        $this->assertEquals('13030/tst17p', $noid);
        # echo 'last noid was "13030/tst17p"';

        # Try to mint another, after they are exhausted.
        $cmd = "{$noid_cmd} mint 1 2>&1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Clean up each line.
        $noidOutput0 = trim($output);
        $noidOutput0 = preg_match('/^error: identifiers exhausted/', $noidOutput0);
        $this->assertNotEmpty($noidOutput0);
        # echo 'correctly disallowed minting after identifiers were exhausted';
    }

    /**
     * Tests for Noid (2).
     *
     * ------------------------------------
     *
     * Project: Noid
     *
     * Name:        noid2.t
     *
     * Function:    To test the noid command.
     *
     * What Is Tested:
     *      Create a minter.
     *      Queue something.
     *      Check that it was logged properly.
     *
     * Command line parameters:  none.
     *
     * Author:  Michael A. Russell
     *
     * Revision History:
     *      7/19/2004 - MAR - Initial writing
     *
     * ------------------------------------
     * @throws Exception
     */
    public function testNoidSqlite2()
    {
        $noid_cmd = $this->cmd . ' -f ' . $this->dir . ' ' . ' -t ' . self::dbtype . ' ';
        # Start off by doing a dbcreate.
        # First, though, make sure that the BerkeleyDB files do not exist.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst2.rde long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Check that the "NOID" subdirectory was created.
        $this->assertFileExists($this->noid_dir, 'no minter directory created, stopped');
        # echo 'NOID was created';

        # That "NOID" is a directory.
        $this->assertTrue(is_dir($this->noid_dir), 'NOID is not a directory, stopped');
        # echo 'NOID is a directory';

        # Check for the presence of the "README" file, then "log" file, then the
        # "logbdb" file within "NOID".
        $this->assertFileExists($this->noid_dir . 'README');
        # echo 'NOID/README was created';
        $this->assertFileExists($this->noid_dir . 'log');
        # echo 'NOID/log was created';

        # Check for the presence of the BerkeleyDB file within "NOID".
        $this->assertFileExists($this->noid_dir . 'noid.sqlite', 'minter initialization failed, stopped');
        # echo 'NOID/noid.bdb was created';

        # Try to queue one.
        $cmd = "{$noid_cmd} queue now 13030/tst27h >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

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
        $regex = preg_quote('m: q|', '@') . '\d\d\d\d\d\d\d\d\d\d\d\d\d\d' . preg_quote('|', '@') . '[a-zA-Z0-9_-]*/[a-zA-Z0-9_-]*(?: \([a-zA-Z0-9_-]*/[a-zA-Z0-9_-]*\))?' . preg_quote('|0', '@');
        $this->assertTrue((bool) preg_match('@' . $regex . '@', $log_lines[2]));
        # echo 'line 3 of "' . $this->noid_dir . 'log" correct';
        $this->assertTrue((bool) preg_match('/^id: 13030\/tst27h added to queue under :\/q\//', $log_lines[3]));
        # echo 'line 4 of "' . $this->noid_dir . 'log" correct';
    }

    /**
     * Tests for Noid (3).
     *
     * ------------------------------------
     *
     * Project: Noid
     *
     * Name:        noid3.t
     *
     * Function:    To test the noid command.
     *
     * What Is Tested:
     *      Create minter.
     *      Hold identifiers that would normally be first and second.
     *      Mint 1 and check that it is what would normally be third.
     *
     * Command line parameters:  none.
     *
     * Author:  Michael A. Russell
     *
     * Revision History:
     *      7/19/2004 - MAR - Initial writing
     *
     * ------------------------------------
     * @throws Exception
     */
    public function testNoidSqlite3()
    {
        $noid_cmd = $this->cmd . ' -f ' . $this->dir . ' ' . ' -t ' . self::dbtype . ' ';
        # Start off by doing a dbcreate.
        # First, though, make sure that the BerkeleyDB files do not exist.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst3.rde long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Check that the "NOID" subdirectory was created.
        $this->assertFileExists($this->noid_dir, 'no minter directory created, stopped');
        # echo 'NOID was created';

        # That "NOID" is a directory.
        $this->assertTrue(is_dir($this->noid_dir), 'NOID is not a directory, stopped');
        # echo 'NOID is a directory';

        # Check for the presence of the "README" file, then "log" file, then the
        # "logbdb" file within "NOID".
        $this->assertFileExists($this->noid_dir . 'README');
        # echo 'NOID/README was created';
        $this->assertFileExists($this->noid_dir . 'log');
        # echo 'NOID/log was created';

        # Check for the presence of the BerkeleyDB file within "NOID".
        $this->assertFileExists($this->noid_dir . 'noid.sqlite', 'minter initialization failed, stopped');
        # echo 'NOID/noid.bdb was created';

        # Hold first and second identifiers.
        $cmd = "{$noid_cmd} hold set 13030/tst31q 13030/tst30f > /dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Mint 1.
        $cmd = "{$noid_cmd} mint 1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Verify that it's the third one.
        $noid_output = trim($output);
        $this->assertEquals('id: 13030/tst394', $noid_output);
        # echo 'held two, minted one, got the third one';
    }

    /**
     * Tests for Noid (4).
     *
     * ------------------------------------
     *
     * Project: Noid
     *
     * Name:        noid4.t
     *
     * Function:    To test the noid command.
     *
     * What Is Tested:
     *      Create minter with template de, for 290 identifiers.
     *      Mint 10.
     *      Queue 3, hold 2, that would have been minted in the
     *          next 20.
     *      Mint 20 and check that they come out in the correct order.
     *
     * Command line parameters:  none.
     *
     * Author:  Michael A. Russell
     *
     * Revision History:
     *      7/19/2004 - MAR - Initial writing
     *
     * ------------------------------------
     * @throws Exception
     */
    public function testNoidSqlite4()
    {
        $noid_cmd = $this->cmd . ' -f ' . $this->dir . ' ' . ' -t ' . self::dbtype . ' ';
        # Start off by doing a dbcreate.
        # First, though, make sure that the BerkeleyDB files do not exist.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst4.rde long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Check that the "NOID" subdirectory was created.
        $this->assertFileExists($this->noid_dir, 'no minter directory created, stopped');
        # echo 'NOID was created';

        # That "NOID" is a directory.
        $this->assertTrue(is_dir($this->noid_dir), 'NOID is not a directory, stopped');
        # echo 'NOID is a directory';

        # Check for the presence of the "README" file, then "log" file, then the
        # "logbdb" file within "NOID".
        $this->assertFileExists($this->noid_dir . 'README');
        # echo 'NOID/README was created';
        $this->assertFileExists($this->noid_dir . 'log');
        # echo 'NOID/log was created';

        # Check for the presence of the BerkeleyDB file within "NOID".
        $this->assertFileExists($this->noid_dir . 'noid.sqlite', 'minter initialization failed, stopped');
        # echo 'NOID/noid.bdb was created';

        # Mint 10.
        $cmd = "{$noid_cmd} mint 10 > /dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Queue 3.
        $cmd = "{$noid_cmd} queue now 13030/tst43m 13030/tst47h 13030/tst44k >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Hold 2.
        $cmd = "{$noid_cmd} hold set 13030/tst412 13030/tst421 >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Mint 20, and check that they have come out in the correct order.
        $cmd = "{$noid_cmd} mint 20";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Remove trailing newlines, and delete the last line if it's empty.
        $noid_output = explode(PHP_EOL, $output);
        $noid_output = array_map('trim', $noid_output);
        # If the last one is the null string, delete it.
        $noid_output = array_filter($noid_output, 'strlen');
        $this->assertEquals(20, count($noid_output),
            # If we don't have exactly 20, something is probably very wrong.
            'wrong number of ids minted, stopped');
        # echo 'number of minted noids';

        $this->assertEquals('id: 13030/tst43m', $noid_output[0], 'Error in 1st minted noid');
        $this->assertEquals('id: 13030/tst47h', $noid_output[1], 'Error in 2nd minted noid');
        $this->assertEquals('id: 13030/tst44k', $noid_output[2], 'Error in 3rd minted noid');
        $this->assertEquals('id: 13030/tst48t', $noid_output[3], 'Error in 4th minted noid');
        $this->assertEquals('id: 13030/tst466', $noid_output[4], 'Error in 5th minted noid');
        $this->assertEquals('id: 13030/tst44x', $noid_output[5], 'Error in 6th minted noid');
        $this->assertEquals('id: 13030/tst42c', $noid_output[6], 'Error in 7th minted noid');
        $this->assertEquals('id: 13030/tst49s', $noid_output[7], 'Error in 8th minted noid');
        $this->assertEquals('id: 13030/tst48f', $noid_output[8], 'Error in 9th minted noid');
        $this->assertEquals('id: 13030/tst475', $noid_output[9], 'Error in 10th minted noid');
        $this->assertEquals('id: 13030/tst45v', $noid_output[10], 'Error in 11th minted noid');
        $this->assertEquals('id: 13030/tst439', $noid_output[11], 'Error in 12th minted noid');
        $this->assertEquals('id: 13030/tst40q', $noid_output[12], 'Error in 13th minted noid');
        $this->assertEquals('id: 13030/tst49f', $noid_output[13], 'Error in 14th minted noid');
        $this->assertEquals('id: 13030/tst484', $noid_output[14], 'Error in 15th minted noid');
        $this->assertEquals('id: 13030/tst46t', $noid_output[15], 'Error in 16th minted noid');
        $this->assertEquals('id: 13030/tst45h', $noid_output[16], 'Error in 17th minted noid');
        $this->assertEquals('id: 13030/tst447', $noid_output[17], 'Error in 18th minted noid');
        $this->assertEquals('id: 13030/tst42z', $noid_output[18], 'Error in 19th minted noid');
        $this->assertEquals('id: 13030/tst41n', $noid_output[19], 'Error in 20th minted noid');
    }

    /**
     * Tests for Noid (5).
     *
     * ------------------------------------
     *
     * Project: Noid
     *
     * Name:        noid5.t
     *
     * Function:    To test the noid command.
     *
     * What Is Tested:
     *      Create minter with template de, for 290 identifiers.
     *      Try to bind to the 3rd identifier that would be minted,
     *          and check that it failed.
     *
     * Command line parameters:  none.
     *
     * Author:  Michael A. Russell
     *
     * Revision History:
     *      7/19/2004 - MAR - Initial writing
     *
     * ------------------------------------
     * @throws Exception
     */
    public function testNoidSqlite5()
    {
        $noid_cmd = $this->cmd . ' -f ' . $this->dir . ' ' . ' -t ' . self::dbtype . ' ';
        # Start off by doing a dbcreate.
        # First, though, make sure that the BerkeleyDB files do not exist.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst5.rde long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Check that the "NOID" subdirectory was created.
        $this->assertFileExists($this->noid_dir, 'no minter directory created, stopped');
        # echo 'NOID was created';

        # That "NOID" is a directory.
        $this->assertTrue(is_dir($this->noid_dir), 'NOID is not a directory, stopped');
        # echo 'NOID is a directory';

        # Check for the presence of the "README" file, then "log" file, then the
        # "logbdb" file within "NOID".
        $this->assertFileExists($this->noid_dir . 'README');
        # echo 'NOID/README was created';
        $this->assertFileExists($this->noid_dir . 'log');
        # echo 'NOID/log was created';

        # Check for the presence of the BerkeleyDB file within "NOID".
        $this->assertFileExists($this->noid_dir . 'noid.sqlite', 'minter initialization failed, stopped');
        # echo 'NOID/noid.bdb was created';

        # Try binding the 3rd identifier to be minted.
        $cmd = "{$noid_cmd} bind set 13030/tst594 element value 2>&1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        $noid_output = explode(PHP_EOL, $output);
        $noid_output = array_map('trim', $noid_output);
        $noid_output = array_filter($noid_output, 'strlen');
        $this->assertGreaterThanOrEqual(1, count($noid_output));
        # echo 'at least one line of output from attempt to bind to an unminted id';

        $msg = 'error: 13030/tst594: "long" term disallows binding ' .
            'an unissued identifier unless a hold is first placed on it.';
        $this->assertEquals($msg, $noid_output[0]);
        # echo 'disallowed binding to unminted id';
    }

    /**
     * Tests for Noid (6).
     *
     * ------------------------------------
     *
     * Project: Noid
     *
     * Name:        noid6.t
     *
     * Function:    To test the noid command.
     *
     * What Is Tested:
     *      Create minter with template de, for 290 identifiers.
     *      Mint 1 noid.
     *      Bind to it a bunch of element/value pairs.
     *      Fetch the bindings to see if everything is there.
     *
     * Command line parameters:  none.
     *
     * Author:  Michael A. Russell
     *
     * Revision History:
     *      7/20/2004 - MAR - Initial writing
     *
     * ------------------------------------
     * @throws Exception
     */
    public function testNoidSqlite6()
    {
        $noid_cmd = $this->cmd . ' -f ' . $this->dir . ' ' . ' -t ' . self::dbtype . ' ';
        # Start off by doing a dbcreate.
        # First, though, make sure that the BerkeleyDB files do not exist.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst6.rde long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Check that the "NOID" subdirectory was created.
        $this->assertFileExists($this->noid_dir, 'no minter directory created, stopped');
        # echo 'NOID was created';

        # That "NOID" is a directory.
        $this->assertTrue(is_dir($this->noid_dir), 'NOID is not a directory, stopped');
        # echo 'NOID is a directory';

        # Check for the presence of the "README" file, then "log" file, then the
        # "logbdb" file within "NOID".
        $this->assertFileExists($this->noid_dir . 'README');
        # echo 'NOID/README was created';
        $this->assertFileExists($this->noid_dir . 'log');
        # echo 'NOID/log was created';

        # Check for the presence of the BerkeleyDB file within "NOID".
        $this->assertFileExists($this->noid_dir . 'noid.sqlite', 'minter initialization failed, stopped');
        # echo 'NOID/noid.bdb was created';

        # Mint one.
        $cmd = "{$noid_cmd} mint 1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        $noid_output = explode(PHP_EOL, $output);
        $noid_output = array_map('trim', $noid_output);
        $noid_output = array_filter($noid_output, 'strlen');

        $bound_noid = preg_replace('/^id:\s+/', '', $noid_output[0]);
        $this->assertNotEmpty($bound_noid);
        # echo '"id: " preceded minted noid';
        unset($noid_output);

        # Set up the elements and values that we'll bind to this noid.
        $bind_stuff = array();
        while (count($bind_stuff) < 100) {
            # If we create a duplicate element name (not likely), it will
            # be overwritten in the hash.  No big deal.
            $bind_stuff[$this->_random_string()] = $this->_random_string();
        }

        # Start the "bind set" command, so that we'll be able to "print" the
        # elements and values.
        $cmd = "{$noid_cmd} bind set $bound_noid : >/dev/null";
        // Save the bind stuff in a temp file in order to simulate STDIN.
        $stdinFilename = tempnam(sys_get_temp_dir(), 'noidtest');
        $stuff = '';
        foreach ($bind_stuff as $key => $value) {
            $stuff .= "$key: $value" . PHP_EOL;
        }
        // An empty liine means end of the input.
        $stuff .= PHP_EOL;
        $result = file_put_contents($stdinFilename, $stuff);
        $this->assertNotEmpty($result);

        $cmd .= ' < ' . escapeshellarg($stdinFilename);
        $this->_executeCommand($cmd, $status, $output, $errors);
        unlink($stdinFilename);
        $this->assertEquals(0, $status, sprintf('open of "%s" failed, %s, stopped', $cmd, $errors));

        # Now, run the "fetch" command to get it all back.
        $cmd = "{$noid_cmd} fetch $bound_noid";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        $noid_output = explode(PHP_EOL, $output);
        $this->assertGreaterThanOrEqual(1, count($noid_output));
        # echo '"fetch" command generated some output';

        # Remove all newlines.
        $noid_output = array_map('trim', $noid_output);
        # If the last line is empty, delete it.
        $noid_output = array_filter($noid_output, 'strlen');
        $this->assertEquals(102, count($noid_output),
            # If there aren't 102 lines of output, somethings is wrong.
            'something wrong with fetch output, stopped');
        # echo 'there are 102 lines of output from the "fetch" command';

        # Check first line.
        $regex = '/^id:\s+' . preg_quote($bound_noid, '/') . '\s+hold\s*$/';
        $this->assertEquals(1, preg_match($regex, $noid_output[0]));
        # echo 'line 1 of "fetch" output';

        # Check seocnd line.
        $regex = '/^Circ:\s+/';
        $this->assertEquals(1, preg_match($regex, $noid_output[1]));
        # echo 'line 2 of "fetch" output';

        # Remove the first two lines from the array.
        array_shift($noid_output);
        array_shift($noid_output);

        # Run through the rest, looking to see if they're correct.
        for ($i = 0; $i < 100; $i++) {
            $this->assertEquals(1, preg_match('/^\s*(\S+)\s*:\s*(\S+)\s*$/', $noid_output[$i], $matches),
                sprintf('line %d of "fetch" output: ("%s") is in an unexpected format',
                    $i + 3, $noid_output[$i]));

            $element = $matches[1];
            $value = $matches[2];
            $this->assertArrayHasKey($element, $bind_stuff,
                sprintf('line %d of "fetch" output: ("%s") contained an element that was not in the group of elements bound to this noid',
                    $i + 3, $noid_output[$i]));

            $this->assertEquals($bind_stuff[$element], $value,
                sprintf('line %d of "fetch" output:  element "%s" was bound to value "%s", but "fetch" returned that it was bound to value "%s"',
                    $i + 3, $element, $bind_stuff[$element], $value));
            # echo 'line ' . $i + 3 . ' of "fetch" output';
            unset($bind_stuff[$element]);
        }

        # Everything that was bound and has been verified has been deleted from
        # the hash.  So the hash should now be empty.
        $this->assertEmpty($bind_stuff);
        # echo 'everything that was bound was returned by the "fetch" command';
    }

    /**
     * Tests for Noid (7).
     *
     * ------------------------------------
     *
     * Project: Noid
     *
     * Name:        noid7.t
     *
     * Function:    To test the noid command.
     *
     * What Is Tested:
     *      Create minter with template de, for 290 identifiers.
     *      Mint 2 noids.
     *      Bind an element/value to the first one using the ":"
     *          option.
     *      Bind an element/value, with the element length greater
     *          than 1,500 characters, and the value being
     *          10 lines, to the second one using the ":-" option.
     *      Fetch the bindings and check that they are correct.
     *
     * Command line parameters:  none.
     *
     * Author:  Michael A. Russell
     *
     * Revision History:
     *      7/20/2004 - MAR - Initial writing
     *
     * ------------------------------------
     * @throws Exception
     */
    public function testNoidSqlite7()
    {
        $noid_cmd = $this->cmd . ' -f ' . $this->dir . ' ' . ' -t ' . self::dbtype . ' ';
        # Start off by doing a dbcreate.
        # First, though, make sure that the BerkeleyDB files do not exist.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst7.rde long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Check that the "NOID" subdirectory was created.
        $this->assertFileExists($this->noid_dir, 'no minter directory created, stopped');
        # echo 'NOID was created';

        # That "NOID" is a directory.
        $this->assertTrue(is_dir($this->noid_dir), 'NOID is not a directory, stopped');
        # echo 'NOID is a directory';

        # Check for the presence of the "README" file, then "log" file, then the
        # "logbdb" file within "NOID".
        $this->assertFileExists($this->noid_dir . 'README');
        # echo 'NOID/README was created';
        $this->assertFileExists($this->noid_dir . 'log');
        # echo 'NOID/log was created';

        # Check for the presence of the BerkeleyDB file within "NOID".
        $this->assertFileExists($this->noid_dir . 'noid.sqlite', 'minter initialization failed, stopped');
        # echo 'NOID/noid.bdb was created';

        # Mint two.
        $cmd = "{$noid_cmd} mint 2";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Remove all newlines.
        $noid_output = explode(PHP_EOL, $output);
        $noid_output = array_map('trim', $noid_output);
        # If the last line is empty, delete it.
        $noid_output = array_filter($noid_output, 'strlen');

        $noid_output[0] = preg_replace('/^id:\s+/', '', $noid_output[0]);
        $this->assertNotEmpty($noid_output[0]);
        # echo 'first line:  "id: " preceded minted noid';
        $noid_output[1] = preg_replace('/^id:\s+/', '', $noid_output[1]);
        $this->assertNotEmpty($noid_output[1]);
        # echo 'second line:  "id: " preceded minted noid';
        $bound_noid1 = $noid_output[0];
        $bound_noid2 = $noid_output[1];
        unset($noid_output);

        # Generate what we'll bind to noid number 1.
        $element1 = $this->_random_string();
        $value1 = $this->_random_string();

        # Start the "bind set" command for noid number 1, so that we'll be
        # able to "print" the element/value.
        $cmd = "{$noid_cmd} bind set $bound_noid1 :- >/dev/null";
        // Save the bind stuff in a temp file in order to simulate STDIN.
        $stdinFilename = tempnam(sys_get_temp_dir(), 'noidtest');
        $stuff = "$element1: $value1" . PHP_EOL;
        $result = file_put_contents($stdinFilename, $stuff);
        $this->assertNotEmpty($result);

        $cmd .= ' < ' . escapeshellarg($stdinFilename);
        $this->_executeCommand($cmd, $status, $output, $errors);
        //unlink($stdinFilename);
        $this->assertEquals(0, $status, sprintf('open of "%s" failed, %s, stopped', $cmd, $errors));

        # Generate the stuff for noid number 2.
        $element2 = '';
        while (strlen($element2) < 1500) {
            $element2 .= $this->_random_string();
        }

        # Generate 10 lines for the value for noid number 2.
        $value2 = array();
        for ($i = 0; $i < 10; $i++) {
            $value2[] = $this->_random_string();
        }

        # Start the "bind set" command for noid number 2, so that we'll be
        # able to "print" the element/value.
        $cmd = "{$noid_cmd} bind set $bound_noid2 :- >/dev/null";
        // Save the bind stuff in a temp file in order to simulate STDIN.
        $stdinFilename = tempnam(sys_get_temp_dir(), 'noidtest');
        # Write the element/value pair.
        $stuff = "$element2 : $value2[0]" . PHP_EOL;
        for ($i = 1; $i < 10; $i++) {
            $stuff .= $value2[$i] . PHP_EOL;
        }
        $result = file_put_contents($stdinFilename, $stuff);
        $this->assertNotEmpty($result);

        $cmd .= ' < ' . escapeshellarg($stdinFilename);
        $this->_executeCommand($cmd, $status, $output, $errors);
        //unlink($stdinFilename);
        $this->assertEquals(0, $status, sprintf('open of "%s" failed, %s, stopped', $cmd, $errors));

        # Now, run the "fetch" command on the noid number 1.
        $cmd = "{$noid_cmd} fetch $bound_noid1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);
        $noid_output = explode(PHP_EOL, $output);
        $this->assertGreaterThan(0, count($noid_output));
        # echo '"fetch" command on noid 1 generated some output';

        # Remove all newlines.
        $noid_output = array_map('trim', $noid_output);
        # Delete any trailing lines that are empty.
        $noid_output = array_filter($noid_output, 'strlen');

        # If there aren't 3 lines of output, somethings is wrong.
        $this->assertEquals(3, count($noid_output), 'something wrong with fetch output, stopped');
        #echo 'there are 3 lines of output from the "fetch" command on noid 1';

        # Check first line.
        $regex = '/^id:\s+' . preg_quote($bound_noid1, '/') . '\s+hold\s*$/';
        $this->assertEquals(1, preg_match($regex, $noid_output[0]));
        # echo 'line 1 of "fetch" output for noid 1';

        # Check second line.
        $regex = '/^Circ:\s+/';
        $this->assertEquals(1, preg_match($regex, $noid_output[1]));
        # echo 'line 2 of "fetch" output for noid 1';

        # Check third line.
        $regex = '/^\s*(\S+)\s*:\s*(\S+)\s*$/';
        $this->assertEquals(1, preg_match($regex, $noid_output[2], $matches),
            'something wrong with bound value, stopped');
        # echo 'line 3 of "fetch" output for noid 1';

        $this->assertEquals($element1, $matches[1]);
        $this->assertEquals($value1, $matches[2]);
        # echo 'line 3 of "fetch" output for noid 1';

        $cmd = "{$noid_cmd} fetch $bound_noid2";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        $noid_output = explode(PHP_EOL, $output);
        $this->assertGreaterThan(0, count($noid_output));
        # echo '"fetch" command on noid 2 generated some output';

        # Remove all newlines.
        $noid_output = array_map('trim', $noid_output);
        # Delete any trailing lines that are empty.
        $noid_output = array_filter($noid_output, 'strlen');

        # If there aren't 12 lines of output, somethings is wrong.
        $this->assertEquals(12, count($noid_output), 'not enough lines of output, stopped');
        #echo 'there are 12 lines of output from the "fetch" command on noid 1';

        # Check first line.
        $regex = '/^id:\s+' . preg_quote($bound_noid2, '/') . '\s+hold\s*$/';
        $this->assertEquals(1, preg_match($regex, $noid_output[0]));
        # echo 'line 1 of "fetch" output for noid 2';

        # Check second line.
        $regex = '/^Circ:\s+/';
        $this->assertEquals(1, preg_match($regex, $noid_output[1]));
        # echo 'line 2 of "fetch" output for noid 2';

        # Check third line.
        $regex = '/^\s*(\S+)\s*:\s*(\S+)\s*$/';
        $this->assertEquals(1, preg_match($regex, $noid_output[2], $matches),
            'something wrong with bound value, stopped');
        # echo 'line 3 of "fetch" output for noid 2';

        $this->assertEquals($element2, $matches[1]);
        $this->assertEquals($value2[0], $matches[2]);
        # echo 'line 3 of "fetch" output for noid 2';

        for ($i = 1; $i <= 9; $i++) {
            $this->assertEquals($value2[$i], $noid_output[$i + 2]);
            # echo 'line ' . $i + 3 . ' of "fetch" output for noid 2';
        }
    }

    /**
     * Tests for Noid (8).
     *
     * ------------------------------------
     *
     * Project: Noid
     *
     * Name:        noid8.t
     *
     * Function:    To test the noid command.
     *
     * What Is Tested:
     *      Do a "dbcreate" using a variety of options to
     *      test that the various options in the policy can
     *      be turned on and off.
     *
     * Command line parameters:  none.
     *
     * Author:  Michael A. Russell
     *
     * Revision History:
     *      7/21/2004 - MAR - Initial writing
     *
     * ------------------------------------
     * @throws Exception
     */
    public function testNoidSqlite8()
    {
        $noid_cmd = $this->cmd . ' -f ' . $this->dir . ' ' . ' -t ' . self::dbtype . ' ';
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate .rde long 13030 cdlib.org noidTest";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Do dbcreate.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate .rddk long 13030 cdlib.org noidTest";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Get and check the policy.
        $policy = $this->_get_policy($this->noid_dir . 'README');
        $this->assertNotEmpty($policy, 'unable to get policy');
        $this->assertEquals('GRANITE', $policy);
        # echo 'policy "GRANITE"';

        # Do dbcreate.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate .rddk long 00000 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Get and check the policy.
        $policy = $this->_get_policy($this->noid_dir . 'README');
        $this->assertNotEmpty($policy, 'unable to get policy');
        $this->assertEquals('-RANITE', $policy);
        # echo 'policy "-RANITE"';

        # Do dbcreate.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate .sddk long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Get and check the policy.
        $policy = $this->_get_policy($this->noid_dir . 'README');
        $this->assertNotEmpty($policy, 'unable to get policy');
        $this->assertEquals('G-ANITE', $policy);
        # echo 'policy "G-ANITE"';

        # Do dbcreate.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst8.rdek long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Get and check the policy.
        $policy = $this->_get_policy($this->noid_dir . 'README');
        $this->assertNotEmpty($policy, 'unable to get policy');
        $this->assertEquals('GR-NITE', $policy);
        # echo 'policy "GR-NITE"';

        # Do dbcreate.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate .rddk medium 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Get and check the policy.
        $policy = $this->_get_policy($this->noid_dir . 'README');
        $this->assertNotEmpty($policy, 'unable to get policy');
        $this->assertEquals('GRA-ITE', $policy);
        # echo 'policy "GRA-ITE"';

        # Do dbcreate.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate r-r.rdd long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Get and check the policy.
        $policy = $this->_get_policy($this->noid_dir . 'README');
        $this->assertNotEmpty($policy, 'unable to get policy');
        $this->assertEquals('GRAN--E', $policy);
        # echo 'policy "GRAN--E"';

        # Do dbcreate.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate .rdd long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Get and check the policy.
        $policy = $this->_get_policy($this->noid_dir . 'README');
        $this->assertNotEmpty($policy, 'unable to get policy');
        $this->assertEquals('GRANI-E', $policy);
        # echo 'policy "GRANI-E"';

        # Do dbcreate.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate a.rdd long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Get and check the policy.
        $policy = $this->_get_policy($this->noid_dir . 'README');
        $this->assertNotEmpty($policy, 'unable to get policy');
        $this->assertEquals('GRANI--', $policy);
        # echo 'policy "GRANI--"';

        # Do dbcreate.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate a-a.seeeeee medium 00000 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Get and check the policy.
        $policy = $this->_get_policy($this->noid_dir . 'README');
        $this->assertNotEmpty($policy, 'unable to get policy');
        $this->assertEquals('-------', $policy);
        # echo 'policy "-------"';

        echo $output;
    }
}
