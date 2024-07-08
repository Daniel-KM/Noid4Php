<?php
/**
 * @author Michael A. Russell
 * @author Daniel Berthereau (conversion to Php)
 * @package Noid
 */

namespace NoidTest;

use Exception;

/**
 * Tests for PDO storage backend.
 *
 * Tests the PDO storage implementation using SQLite driver.
 *
 * @covers \Noid\Lib\Db
 * @covers \Noid\Storage\PdoDb
 */
class NoidPdoTest extends NoidTestCase
{
    const dbtype = 'pdo';

    /**
     * Tests for Noid (1) with PDO.
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
     * @throws Exception
     */
    public function testNoidPdo1()
    {
        $noid_cmd = $this->cmd . ' -f ' . escapeshellarg($this->settings_file) . ' ' . ' -t ' . self::dbtype . ' ';
        # Start off by doing a dbcreate.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst1.rde long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Check that the "NOID" subdirectory was created.
        $this->assertFileExists($this->noid_dir, 'No minter directory created, stopped');

        # That "NOID" is a directory.
        $this->assertTrue(is_dir($this->noid_dir), 'NOID is not a directory, stopped');

        # Check for the presence of the "README" file and "log" file.
        $this->assertFileExists($this->noid_dir . 'README');
        $this->assertFileExists($this->noid_dir . 'log');

        # Check for the presence of the database file within "NOID".
        $this->assertFileExists($this->noid_dir . 'noid.sqlite', 'Minter initialization failed, stopped');

        # Mint all but the last two of 290.
        $cmd = "{$noid_cmd} mint 288";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Clean up each output line.
        $noid_output = explode(PHP_EOL, $output);
        foreach ($noid_output as &$no) {
            $no = trim($no);
            $no = preg_replace('/^\s*id:\s+/', '', $no);
        }
        # If the last one is the null string, delete it.
        $noid_output = array_filter($noid_output, 'strlen');
        $noid_output = array_values($noid_output);
        # We expect to have 288 entries.
        $this->assertEquals(288, count($noid_output));

        # Save number 20, number 55, and number 155.
        $save_noid[0] = $noid_output[20];
        $save_noid[1] = $noid_output[55];
        $save_noid[2] = $noid_output[155];
        unset($noid_output);

        # Mint the next to last one.
        $cmd = "{$noid_cmd} mint 1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);
        # Remove leading "id: ".
        $noid = preg_replace('/^id:\s+/', '', $output);
        $this->assertNotEmpty($noid);
        # Remove trailing white space.
        $noid = preg_replace('/\s+$/', '', $noid);
        $this->assertNotEmpty($noid);
        $this->assertEquals('13030/tst190', $noid);

        # Try to queue one of the 3. It shouldn't let me, because the hold must
        # be released first.
        $cmd = "{$noid_cmd} queue now $save_noid[0] 2>&1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Verify that it won't let me.
        $noidOutput0 = trim($output);
        $noidOutput0 = preg_match('/^error: a hold has been set for .* and must be released before the identifier can be queued for minting/', $noidOutput0);
        $this->assertNotEmpty($noidOutput0);

        # Release the hold on the 3 minted noids.
        $cmd = "{$noid_cmd} hold release $save_noid[0] $save_noid[1] $save_noid[2] > /dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Queue those 3.
        $cmd = "{$noid_cmd} queue now $save_noid[0] $save_noid[1] $save_noid[2] > /dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Mint them.
        $cmd = "{$noid_cmd} mint 3";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Clean up each line.
        $noid_output = explode(PHP_EOL, $output);
        foreach ($noid_output as &$no) {
            $no = trim($no);
            $no = preg_replace('/^\s*id:\s+/', '', $no);
        }
        # If the last one is the null string, delete it.
        $noid_output = array_filter($noid_output, 'strlen');
        $noid_output = array_values($noid_output);
        # We expect to have 3 entries.
        $this->assertEquals(3, count($noid_output));

        # Check their values.
        $this->assertEquals($save_noid[0], $noid_output[0]);
        $this->assertEquals($save_noid[1], $noid_output[1]);
        $this->assertEquals($save_noid[2], $noid_output[2]);
        unset($save_noid);
        unset($noid_output);

        # Mint the last one.
        $cmd = "{$noid_cmd} mint 1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);
        # Remove leading "id: ".
        $noid = preg_replace('/^id:\s+/', '', $output);
        $this->assertNotEmpty($noid);
        # Remove trailing white space.
        $noid = preg_replace('/\s+$/', '', $noid);
        $this->assertNotEmpty($noid);
        $this->assertEquals('13030/tst17p', $noid);

        # Try to mint another, after they are exhausted.
        $cmd = "{$noid_cmd} mint 1 2>&1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Clean up each line.
        $noidOutput0 = trim($output);
        $noidOutput0 = preg_match('/^error: identifiers exhausted/', $noidOutput0);
        $this->assertNotEmpty($noidOutput0);
    }

    /**
     * Tests for Noid (2) with PDO.
     *
     * What Is Tested:
     * - Create a minter.
     * - Queue something.
     * - Check that it was logged properly.
     *
     * @throws Exception
     */
    public function testNoidPdo2()
    {
        $noid_cmd = $this->cmd . ' -f ' . escapeshellarg($this->settings_file) . ' ' . ' -t ' . self::dbtype . ' ';
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst2.rde long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Check that the "NOID" subdirectory was created.
        $this->assertFileExists($this->noid_dir, 'No minter directory created, stopped');
        $this->assertTrue(is_dir($this->noid_dir), 'NOID is not a directory, stopped');
        $this->assertFileExists($this->noid_dir . 'README');
        $this->assertFileExists($this->noid_dir . 'log');
        $this->assertFileExists($this->noid_dir . 'noid.sqlite', 'Minter initialization failed, stopped');

        # Try to queue one.
        $cmd = "{$noid_cmd} queue now 13030/tst27h >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Examine the contents of the log.
        $fh = fopen($this->noid_dir . 'log', 'r');
        $this->assertNotEmpty($fh, 'Failed to open log file, stopped');

        # Read in the log.
        fclose($fh);
        $log_lines = file($this->noid_dir . 'log');

        $this->assertEquals(4, count($log_lines),
            'Log_lines: ' . implode(', ', $log_lines));

        # Remove trailing newlines.
        $log_lines = array_map('trim', $log_lines);

        # Check the contents of the lines.
        $this->assertEquals('Creating database for template "tst2.rde".', $log_lines[0]);
        $this->assertEquals('note: id 13030/tst27h being queued before first minting (to be pre-cycled)', $log_lines[1]);
        $regex = preg_quote('m: q|', '@') . '\d\d\d\d\d\d\d\d\d\d\d\d\d\d' . preg_quote('|', '@') . '[a-zA-Z0-9_-]*/[a-zA-Z0-9_-]*(?: \([a-zA-Z0-9_-]*/[a-zA-Z0-9_-]*\))?' . preg_quote('|0', '@');
        $this->assertTrue((bool) preg_match('@' . $regex . '@', $log_lines[2]));
        $this->assertTrue((bool) preg_match('/^id: 13030\/tst27h added to queue under :\/q\//', $log_lines[3]));
    }

    /**
     * Tests for Noid (3) with PDO.
     *
     * What Is Tested:
     * - Create minter.
     * - Hold identifiers that would normally be first and second.
     * - Mint 1 and check that it is what would normally be third.
     *
     * @throws Exception
     */
    public function testNoidPdo3()
    {
        $noid_cmd = $this->cmd . ' -f ' . escapeshellarg($this->settings_file) . ' ' . ' -t ' . self::dbtype . ' ';
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst3.rde long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        $this->assertFileExists($this->noid_dir, 'No minter directory created, stopped');
        $this->assertTrue(is_dir($this->noid_dir), 'NOID is not a directory, stopped');
        $this->assertFileExists($this->noid_dir . 'README');
        $this->assertFileExists($this->noid_dir . 'log');
        $this->assertFileExists($this->noid_dir . 'noid.sqlite', 'Minter initialization failed, stopped');

        # Hold first and second identifiers.
        $cmd = "{$noid_cmd} hold set 13030/tst31q 13030/tst30f > /dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Mint 1.
        $cmd = "{$noid_cmd} mint 1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Verify that it's the third one.
        $noid_output = trim($output);
        $this->assertEquals('id: 13030/tst394', $noid_output);
    }

    /**
     * Tests for Noid (4) with PDO.
     *
     * What Is Tested:
     * - Create minter with template de, for 290 identifiers.
     * - Mint 10.
     * - Queue 3, hold 2, that would have been minted in the next 20.
     * - Mint 20 and check that they come out in the correct order.
     *
     * @throws Exception
     */
    public function testNoidPdo4()
    {
        $noid_cmd = $this->cmd . ' -f ' . escapeshellarg($this->settings_file) . ' ' . ' -t ' . self::dbtype . ' ';
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst4.rde long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        $this->assertFileExists($this->noid_dir, 'No minter directory created, stopped');
        $this->assertTrue(is_dir($this->noid_dir), 'NOID is not a directory, stopped');
        $this->assertFileExists($this->noid_dir . 'README');
        $this->assertFileExists($this->noid_dir . 'log');
        $this->assertFileExists($this->noid_dir . 'noid.sqlite', 'Minter initialization failed, stopped');

        # Mint 10.
        $cmd = "{$noid_cmd} mint 10 > /dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Queue 3.
        $cmd = "{$noid_cmd} queue now 13030/tst43m 13030/tst47h 13030/tst44k >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Hold 2.
        $cmd = "{$noid_cmd} hold set 13030/tst412 13030/tst421 >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Mint 20, and check that they have come out in the correct order.
        $cmd = "{$noid_cmd} mint 20";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        # Remove trailing newlines, and delete the last line if it's empty.
        $noid_output = explode(PHP_EOL, $output);
        $noid_output = array_map('trim', $noid_output);
        $noid_output = array_filter($noid_output, 'strlen');
        $noid_output = array_values($noid_output);
        $this->assertEquals(20, count($noid_output), 'Wrong number of ids minted, stopped');

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
     * Tests for Noid (5) with PDO.
     *
     * What Is Tested:
     * - Create minter with template de, for 290 identifiers.
     * - Try to bind to the 3rd identifier that would be minted, and check that it failed.
     *
     * @throws Exception
     */
    public function testNoidPdo5()
    {
        $noid_cmd = $this->cmd . ' -f ' . escapeshellarg($this->settings_file) . ' ' . ' -t ' . self::dbtype . ' ';
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst5.rde long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        $this->assertFileExists($this->noid_dir, 'No minter directory created, stopped');
        $this->assertTrue(is_dir($this->noid_dir), 'NOID is not a directory, stopped');
        $this->assertFileExists($this->noid_dir . 'README');
        $this->assertFileExists($this->noid_dir . 'log');
        $this->assertFileExists($this->noid_dir . 'noid.sqlite', 'Minter initialization failed, stopped');

        # Try binding the 3rd identifier to be minted.
        $cmd = "{$noid_cmd} bind set 13030/tst594 element value 2>&1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        $noid_output = explode(PHP_EOL, $output);
        $noid_output = array_map('trim', $noid_output);
        $noid_output = array_filter($noid_output, 'strlen');
        $noid_output = array_values($noid_output);
        $this->assertGreaterThanOrEqual(1, count($noid_output));

        $msg = 'error: 13030/tst594: "long" term disallows binding an unissued identifier unless a hold is first placed on it.';
        $this->assertEquals($msg, $noid_output[0]);
    }
}
