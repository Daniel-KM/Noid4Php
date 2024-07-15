<?php
/**
 * Tests for multiple fetch functionality.
 *
 * @package Noid
 */

namespace NoidTest;

use Exception;
use Noid\Lib\Db;
use Noid\Lib\Log;
use Noid\Noid;
use Noid\Storage\DatabaseInterface;

/**
 * Tests for Noid::fetchMultiple().
 *
 * @covers \Noid\Noid::fetchMultiple
 */
class FetchMultipleTest extends NoidTestCase
{
    const dbtype = 'lmdb';

    /**
     * Clean up after each test.
     */
    public function tearDown(): void
    {
        $this->_removeDatabase();
        parent::tearDown();
    }

    /**
     * Test basic multiple fetch.
     *
     * @throws Exception
     */
    public function testFetchMultipleBasic()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid, 'Failed to open database');

        // Mint and bind some IDs
        $id1 = Noid::mint($noid, 'test@example.org');
        $id2 = Noid::mint($noid, 'test@example.org');

        Noid::bind($noid, 'test@example.org', '-', 'set', $id1, 'title', 'Title 1');
        Noid::bind($noid, 'test@example.org', '-', 'set', $id1, 'author', 'Author 1');
        Noid::bind($noid, 'test@example.org', '-', 'set', $id2, 'title', 'Title 2');

        // Fetch multiple
        $requests = [
            ['id' => $id1, 'elems' => ['title']],
            ['id' => $id2, 'elems' => ['title']],
        ];

        $results = Noid::fetchMultiple($noid, 0, $requests);

        $this->assertIsArray($results);
        $this->assertCount(2, $results);
        $this->assertStringContainsString('Title 1', $results[0]);
        $this->assertStringContainsString('Title 2', $results[1]);

        Db::dbclose($noid);
    }

    /**
     * Test fetchMultiple with verbose mode.
     *
     * @throws Exception
     */
    public function testFetchMultipleVerbose()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $id = Noid::mint($noid, 'test@example.org');
        Noid::bind($noid, 'test@example.org', '-', 'set', $id, 'title', 'Test Title');

        $requests = [['id' => $id, 'elems' => ['title']]];
        $results = Noid::fetchMultiple($noid, 1, $requests);

        $this->assertCount(1, $results);
        $this->assertStringContainsString('id:', $results[0]);
        $this->assertStringContainsString('Circ:', $results[0]);
        $this->assertStringContainsString('title:', $results[0]);
        $this->assertStringContainsString('Test Title', $results[0]);

        Db::dbclose($noid);
    }

    /**
     * Test fetchMultiple with empty elems (fetch all).
     *
     * @throws Exception
     */
    public function testFetchMultipleFetchAll()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $id = Noid::mint($noid, 'test@example.org');
        Noid::bind($noid, 'test@example.org', '-', 'set', $id, 'title', 'Test Title');
        Noid::bind($noid, 'test@example.org', '-', 'set', $id, 'author', 'Test Author');

        // Empty elems = fetch all
        $requests = [['id' => $id, 'elems' => []]];
        $results = Noid::fetchMultiple($noid, 0, $requests);

        $this->assertCount(1, $results);
        $this->assertStringContainsString('Test Title', $results[0]);
        $this->assertStringContainsString('Test Author', $results[0]);

        Db::dbclose($noid);
    }

    /**
     * Test fetchMultiple with empty requests.
     *
     * @throws Exception
     */
    public function testFetchMultipleEmpty()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);

        $results = Noid::fetchMultiple($noid, 0, []);

        $this->assertIsArray($results);
        $this->assertCount(0, $results);

        Db::dbclose($noid);
    }

    /**
     * Test fetchMultiple with invalid id.
     *
     * @throws Exception
     */
    public function testFetchMultipleInvalidId()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);

        $requests = [
            ['id' => '', 'elems' => ['title']],
        ];

        $results = Noid::fetchMultiple($noid, 0, $requests);

        $this->assertCount(1, $results);
        $this->assertNull($results[0]);

        Db::dbclose($noid);
    }

    /**
     * Test fetchMultiple with mixed valid and invalid requests.
     *
     * @throws Exception
     */
    public function testFetchMultipleMixed()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $id = Noid::mint($noid, 'test@example.org');
        Noid::bind($noid, 'test@example.org', '-', 'set', $id, 'title', 'Valid Title');

        $requests = [
            ['id' => $id, 'elems' => ['title']],      // valid
            ['id' => '', 'elems' => ['title']],       // invalid: no id
            ['id' => $id, 'elems' => ['author']],     // valid but unbound element
        ];

        $results = Noid::fetchMultiple($noid, 0, $requests);

        $this->assertCount(3, $results);
        $this->assertStringContainsString('Valid Title', $results[0]);
        $this->assertNull($results[1]);
        $this->assertNotNull($results[2]); // Returns empty or error message

        Db::dbclose($noid);
    }

    /**
     * Test fetchMultiple exceeds max count.
     *
     * @throws Exception
     */
    public function testFetchMultipleExceedsMax()
    {
        $this->_createDatabase('tst9.rde');
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);

        // Create array with 10001 requests
        $requests = [];
        for ($i = 0; $i < 10001; $i++) {
            $requests[] = ['id' => "test$i", 'elems' => ['title']];
        }

        $results = Noid::fetchMultiple($noid, 0, $requests);

        $this->assertIsArray($results);
        $this->assertCount(0, $results);

        $errmsg = Log::errmsg($noid);
        $this->assertStringContainsString('cannot exceed 10000', $errmsg);

        Db::dbclose($noid);
    }

    /**
     * Helper to create a test database.
     *
     * @param string $template
     * @throws Exception
     */
    private function _createDatabase($template)
    {
        $this->_removeDatabase();
        $report = Db::dbcreate($this->settings, 'test@example.org', $template, 'short');
        $this->assertNotEmpty($report, 'Failed to create database: ' . Log::errmsg(null, 1));
    }

    /**
     * Helper to remove test database.
     */
    private function _removeDatabase()
    {
        if (is_dir($this->noid_dir)) {
            $this->_rmdir($this->noid_dir);
        }
    }

    /**
     * Recursively remove directory.
     *
     * @param string $dir
     */
    private function _rmdir($dir)
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->_rmdir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
