<?php
/**
 * @author Michael A. Russell
 * @author Daniel Berthereau (conversion to Php)
 * @package Noid
 */

namespace NoidTest;

use Exception;

/**
 * Tests for Noid (3).
 *
 * ------------------------------------
 *
 * Project: Noid
 *
 * Name: noid3.t
 *
 * Function: To test the noid command.
 *
 * What Is Tested:
 * - Create minter.
 * - Hold identifiers that would normally be first and second.
 * - Mint 1 and check that it is what would normally be third.
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
class Noid3Test extends NoidTestCase
{
    const dbtype = 'bdb';

    /**
     * @throws Exception
     */
    public function testNoid3()
    {
        $noid_cmd = $this->cmd . ' -f ' . escapeshellarg($this->settings_file) . ' ' . ' -t ' . self::dbtype . ' ';
        # Start off by doing a dbcreate.
        # First, though, make sure that the BerkeleyDB files do not exist.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate tst3.rde long 13030 cdlib.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        # Check that the "NOID" subdirectory was created.
        $this->assertFileExists($this->noid_dir, 'No minter directory created, stopped');
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
        $this->assertFileExists($this->noid_dir . 'logbdb');
        # echo 'NOID/logbdb was created';

        # Check for the presence of the BerkeleyDB file within "NOID".
        $this->assertFileExists($this->noid_dir . 'noid.bdb', 'Minter initialization failed, stopped');
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
        $this->assertEquals('Id: 13030/tst394', $noid_output);
        # echo 'held two, minted one, got the third one';
    }
}
