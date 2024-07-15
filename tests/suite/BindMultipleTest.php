<?php
/**
 * Tests for multiple binding functionality.
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
 * Tests for Noid::bindMultiple().
 *
 * @covers \Noid\Noid::bindMultiple
 */
class BindMultipleTest extends NoidTestCase
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
     * Test basic multiple binding.
     *
     * @throws Exception
     */
    public function testBindMultipleBasic()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid, 'Failed to open database');

        // Mint an ID first
        $id = Noid::mint($noid, 'test@example.org');
        $this->assertNotEmpty($id);

        // Bind multiple elements in one call
        $bindings = [
            ['how' => 'set', 'id' => $id, 'elem' => 'title', 'value' => 'Test Title'],
            ['how' => 'set', 'id' => $id, 'elem' => 'author', 'value' => 'John Doe'],
            ['how' => 'set', 'id' => $id, 'elem' => 'date', 'value' => '2024-01-01'],
        ];

        $results = Noid::bindMultiple($noid, 'test@example.org', '-', $bindings);

        $this->assertIsArray($results);
        $this->assertCount(3, $results);

        // All should succeed
        foreach ($results as $i => $result) {
            $this->assertNotNull($result, "Binding $i failed");
            $this->assertStringContainsString('ok', $result);
        }

        // Verify bindings
        $title = Noid::fetch($noid, 0, $id, 'title');
        $this->assertEquals('Test Title', trim($title));

        $author = Noid::fetch($noid, 0, $id, 'author');
        $this->assertEquals('John Doe', trim($author));

        Db::dbclose($noid);
    }

    /**
     * Test bindMultiple is more efficient than individual binds.
     *
     * @throws Exception
     */
    public function testBindMultiplePerformance()
    {
        $count = 20;

        // Time multiple binding
        $this->_createDatabase('tst9.rdee');
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $id1 = Noid::mint($noid, 'test@example.org');

        $bindings = [];
        for ($i = 0; $i < $count; $i++) {
            $bindings[] = ['how' => 'set', 'id' => $id1, 'elem' => "elem$i", 'value' => "value$i"];
        }

        $startMultiple = microtime(true);
        $results = Noid::bindMultiple($noid, 'test@example.org', '-', $bindings);
        $multipleTime = microtime(true) - $startMultiple;

        $this->assertCount($count, $results);
        Db::dbclose($noid);

        // Time individual binding (recreate database)
        $this->_removeDatabase();
        $this->_createDatabase('tst9.rdee');
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $id2 = Noid::mint($noid, 'test@example.org');

        $startSingle = microtime(true);
        for ($i = 0; $i < $count; $i++) {
            Noid::bind($noid, 'test@example.org', '-', 'set', $id2, "elem$i", "value$i");
        }
        $singleTime = microtime(true) - $startSingle;

        Db::dbclose($noid);

        // Multiple should be faster (allow tolerance for system variations)
        $this->assertLessThanOrEqual(
            $singleTime * 1.5,
            $multipleTime,
            sprintf(
                'bindMultiple (%.4fs) should not be much slower than individual binding (%.4fs)',
                $multipleTime,
                $singleTime
            )
        );
    }

    /**
     * Test bindMultiple with empty array.
     *
     * @throws Exception
     */
    public function testBindMultipleEmpty()
    {
        $this->_createDatabase('tst9.rde');
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);

        $results = Noid::bindMultiple($noid, 'test@example.org', '-', []);

        $this->assertIsArray($results);
        $this->assertCount(0, $results);

        Db::dbclose($noid);
    }

    /**
     * Test bindMultiple with mixed valid and invalid bindings.
     *
     * @throws Exception
     */
    public function testBindMultipleMixed()
    {
        $this->_createDatabase('tst9.rde');
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);

        $id = Noid::mint($noid, 'test@example.org');

        $bindings = [
            ['how' => 'set', 'id' => $id, 'elem' => 'valid1', 'value' => 'value1'],  // valid
            ['how' => 'set', 'id' => '', 'elem' => 'elem', 'value' => 'value'],       // invalid: no id
            ['how' => 'set', 'id' => $id, 'elem' => 'valid2', 'value' => 'value2'],  // valid
            ['how' => 'set', 'id' => $id, 'elem' => '', 'value' => 'value'],          // invalid: no elem
        ];

        $results = Noid::bindMultiple($noid, 'test@example.org', '-', $bindings);

        $this->assertCount(4, $results);
        $this->assertNotNull($results[0], 'First binding should succeed');
        $this->assertNull($results[1], 'Second binding should fail (no id)');
        $this->assertNotNull($results[2], 'Third binding should succeed');
        $this->assertNull($results[3], 'Fourth binding should fail (no elem)');

        Db::dbclose($noid);
    }

    /**
     * Test bindMultiple with different operations.
     *
     * @throws Exception
     */
    public function testBindMultipleOperations()
    {
        $this->_createDatabase('tst9.rde');
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);

        $id = Noid::mint($noid, 'test@example.org');

        // First set some values
        $bindings1 = [
            ['how' => 'set', 'id' => $id, 'elem' => 'title', 'value' => 'Original'],
            ['how' => 'set', 'id' => $id, 'elem' => 'desc', 'value' => 'Description'],
        ];
        Noid::bindMultiple($noid, 'test@example.org', '-', $bindings1);

        // Now test different operations
        $bindings2 = [
            ['how' => 'append', 'id' => $id, 'elem' => 'title', 'value' => ' Title'],
            ['how' => 'replace', 'id' => $id, 'elem' => 'desc', 'value' => 'New Description'],
        ];
        $results = Noid::bindMultiple($noid, 'test@example.org', '-', $bindings2);

        $this->assertCount(2, $results);
        $this->assertNotNull($results[0]);
        $this->assertNotNull($results[1]);

        // Verify results
        $title = Noid::fetch($noid, 0, $id, 'title');
        $this->assertEquals('Original Title', trim($title));

        $desc = Noid::fetch($noid, 0, $id, 'desc');
        $this->assertEquals('New Description', trim($desc));

        Db::dbclose($noid);
    }

    /**
     * Test bindMultiple exceeds max count.
     *
     * @throws Exception
     */
    public function testBindMultipleExceedsMax()
    {
        $this->_createDatabase('tst9.rde');
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);

        // Create array with 10001 bindings
        $bindings = [];
        for ($i = 0; $i < 10001; $i++) {
            $bindings[] = ['how' => 'set', 'id' => 'test', 'elem' => "elem$i", 'value' => "val$i"];
        }

        $results = Noid::bindMultiple($noid, 'test@example.org', '-', $bindings);

        $this->assertIsArray($results);
        $this->assertCount(0, $results);

        $errmsg = Log::errmsg($noid);
        $this->assertStringContainsString('cannot exceed 10000', $errmsg);

        Db::dbclose($noid);
    }

    /**
     * Test bindMultiple with delete operation.
     *
     * @throws Exception
     */
    public function testBindMultipleDelete()
    {
        $this->_createDatabase('tst9.rde');
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);

        $id = Noid::mint($noid, 'test@example.org');

        // First set values
        $bindings1 = [
            ['how' => 'set', 'id' => $id, 'elem' => 'title', 'value' => 'Title'],
            ['how' => 'set', 'id' => $id, 'elem' => 'author', 'value' => 'Author'],
        ];
        Noid::bindMultiple($noid, 'test@example.org', '-', $bindings1);

        // Now delete one
        $bindings2 = [
            ['how' => 'delete', 'id' => $id, 'elem' => 'title', 'value' => ''],
        ];
        $results = Noid::bindMultiple($noid, 'test@example.org', '-', $bindings2);

        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]);
        $this->assertStringContainsString('removed', $results[0]);

        // Verify title is deleted but author remains
        $title = Noid::fetch($noid, 0, $id, 'title');
        $this->assertEmpty(trim($title));

        $author = Noid::fetch($noid, 0, $id, 'author');
        $this->assertEquals('Author', trim($author));

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
