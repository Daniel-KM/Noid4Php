<?php
/**
 * @author Daniel Berthereau (conversion to Php)
 * @package Noid
 */

namespace NoidTest;

use Exception;
use Noid\Lib\Db;
use Noid\Noid;
use Noid\Storage\DatabaseInterface;

/**
 * Tests for Noid: create a template with 3721 noids, and mint them all, until a
 * duplicate is found (none!).
 */
class TemplateLongTest extends NoidTestCase
{
    const dbtype = 'bdb';

    /**
     * @throws Exception
     */
    public function testLong()
    {
        $noid_cmd = $this->cmd . ' -f ' . escapeshellarg($this->settings_file) . ' ' . ' -t ' . self::dbtype . ' ';
        $total = 3721;

        # Start off by doing a dbcreate.
        # First, though, make sure that the BerkeleyDB files do not exist.
        $cmd = "{$this->rm_cmd} ; " .
            "{$noid_cmd} dbcreate b.rllk long 99999 example.org noidTest >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

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

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $contact = 'Fester Bestertester';

        $ids = array();
        fwrite(STDERR, PHP_EOL);
        for ($i = 1; $i <= $total; $i++) {
            $id = Noid::mint($noid, $contact, '');
            // The assertion is called separately to process it quickly.
            if ($id === null) {
                $this->assertIsNotNull($id,
                    sprintf('No noid output (current %d).',
                        $i));
            } elseif (isset($ids[$id])) {
                $this->assertArrayHasKey($id, $ids,
                    sprintf('The noid "%s" is already set (order %d, current %d).',
                        $id, $ids[$id], $i));
            } else {
                $ids[$id] = $i;
            }

            if (($i % 100) == 0) {
                fwrite(STDERR, "Processed $i / $total (last: $id)" . PHP_EOL);
            }
        }

        # Try to mint another, after they are exhausted.
        $id = Noid::mint($noid, $contact, '');
        $this->assertEmpty($id);
    }
}
