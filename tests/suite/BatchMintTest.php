<?php
/**
 * Tests for multiple minting functionality.
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
 * Tests for Noid::mintMultiple().
 *
 * @covers \Noid\Noid::mintMultiple
 * @covers \Noid\Noid::_mintBatch
 * @covers \Noid\Noid::_mintOne
 */
class BatchMintTest extends NoidTestCase
{
    const dbtype = 'lmdb';

    /**
     * Test basic multiple minting with small count.
     *
     * @throws Exception
     */
    public function testMintMultipleBasic()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid, 'Failed to open database');

        // Mint 10 identifiers
        $ids = Noid::mintMultiple($noid, 'test@example.org', 10);

        $this->assertIsArray($ids);
        $this->assertCount(10, $ids);

        // Verify all IDs are unique
        $unique = array_unique($ids);
        $this->assertCount(10, $unique, 'Minted IDs should be unique');

        // Verify all IDs are valid format
        foreach ($ids as $id) {
            $this->assertNotEmpty($id);
            $this->assertIsString($id);
        }

        Db::dbclose($noid);
    }

    /**
     * Test multiple minting produces same IDs as sequential mint() calls.
     *
     * @throws Exception
     */
    public function testMintMultipleConsistency()
    {
        // Create two identical databases and compare results
        $this->_createDatabase('tst9.rde');
        $noid1 = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid1);

        // Mint 5 individually
        $singleIds = [];
        for ($i = 0; $i < 5; $i++) {
            $id = Noid::mint($noid1, 'test@example.org');
            $this->assertNotEmpty($id, 'mint() failed at iteration ' . $i);
            $singleIds[] = $id;
        }
        Db::dbclose($noid1);

        // Recreate and mint 5 with mintMultiple
        $this->_removeDatabase();
        $this->_createDatabase('tst9.rde');
        $noid2 = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid2);

        $multipleIds = Noid::mintMultiple($noid2, 'test@example.org', 5);
        Db::dbclose($noid2);

        $this->assertCount(5, $multipleIds);
        $this->assertEquals($singleIds, $multipleIds, 'mintMultiple should produce same IDs as sequential minting');
    }

    /**
     * Test single mint returns string.
     *
     * @throws Exception
     */
    public function testSingleMintReturnsString()
    {
        $this->_createDatabase('tst9.rde');
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        // mint() should return string
        $id = Noid::mint($noid, 'test@example.org');

        $this->assertIsString($id);
        $this->assertNotEmpty($id);

        Db::dbclose($noid);
    }

    /**
     * Test mintMultiple returns array.
     *
     * @throws Exception
     */
    public function testMintMultipleReturnsArray()
    {
        $this->_createDatabase('tst9.rde');
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        // mintMultiple should return array
        $ids = Noid::mintMultiple($noid, 'test@example.org', 2);

        $this->assertIsArray($ids);
        $this->assertCount(2, $ids);

        Db::dbclose($noid);
    }

    /**
     * Test mintMultiple with count of 0 returns empty array.
     *
     * @throws Exception
     */
    public function testMintMultipleZero()
    {
        $this->_createDatabase('tst9.rde');
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        $ids = Noid::mintMultiple($noid, 'test@example.org', 0);

        $this->assertIsArray($ids);
        $this->assertCount(0, $ids);

        $errmsg = Log::errmsg($noid);
        $this->assertStringContainsString('count must be at least 1', $errmsg);

        Db::dbclose($noid);
    }

    /**
     * Test mintMultiple exceeds maximum count.
     *
     * @throws Exception
     */
    public function testMintMultipleExceedsMax()
    {
        $this->_createDatabase('tst9.rde');
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        $ids = Noid::mintMultiple($noid, 'test@example.org', 10001);

        $this->assertIsArray($ids);
        $this->assertCount(0, $ids);

        $errmsg = Log::errmsg($noid);
        $this->assertStringContainsString('cannot exceed 10000', $errmsg);

        Db::dbclose($noid);
    }

    /**
     * Test mintMultiple with empty contact.
     *
     * @throws Exception
     */
    public function testMintMultipleNoContact()
    {
        $this->_createDatabase('tst9.rde');
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        $ids = Noid::mintMultiple($noid, '', 5);

        $this->assertIsArray($ids);
        $this->assertCount(0, $ids);

        Db::dbclose($noid);
    }

    /**
     * Test mintMultiple with larger count (100 IDs).
     *
     * @throws Exception
     */
    public function testMintMultipleLarger()
    {
        $this->_createDatabase('tst9.rdee');  // More capacity
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        $ids = Noid::mintMultiple($noid, 'test@example.org', 100);

        $this->assertIsArray($ids);
        $this->assertCount(100, $ids);

        // Verify uniqueness
        $unique = array_unique($ids);
        $this->assertCount(100, $unique, 'All 100 minted IDs should be unique');

        Db::dbclose($noid);
    }

    /**
     * Test mintMultiple stops when minter is exhausted.
     *
     * @throws Exception
     */
    public function testMintMultipleExhausted()
    {
        // Create a small minter with 'sd' template (sequential, 1 digit = 10 IDs)
        // Using 'short' term allows wrap-around, so use 'medium' to prevent that
        $this->_removeDatabase();
        $report = Db::dbcreate($this->settings, 'test@example.org', 'tst9.sd', 'medium');
        $this->assertNotEmpty($report, 'Failed to create database: ' . Log::errmsg(null, 1));

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        // Get the total capacity
        $total = Db::$engine->get(':/total');
        $this->assertEquals(10, (int)$total, 'Template sd should have capacity of 10');

        // First mint all available
        $ids1 = Noid::mintMultiple($noid, 'test@example.org', 10);
        $this->assertCount(10, $ids1, 'Should mint all 10 available IDs');

        // Try to mint more - should fail
        $ids2 = Noid::mintMultiple($noid, 'test@example.org', 5);
        $this->assertCount(0, $ids2, 'Should not mint more IDs when exhausted');

        Db::dbclose($noid);
    }

    /**
     * Test that mintMultiple is faster than individual minting.
     *
     * @throws Exception
     */
    public function testMintMultiplePerformance()
    {
        $count = 50;

        // Time multiple minting
        $this->_createDatabase('tst9.rdee');
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        $startMultiple = microtime(true);
        $multipleIds = Noid::mintMultiple($noid, 'test@example.org', $count);
        $endMultiple = microtime(true);
        $multipleTime = $endMultiple - $startMultiple;

        Db::dbclose($noid);

        $this->assertCount($count, $multipleIds);

        // Time individual minting (recreate database)
        $this->_removeDatabase();
        $this->_createDatabase('tst9.rdee');

        $startSingle = microtime(true);
        $singleIds = [];
        for ($i = 0; $i < $count; $i++) {
            $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
            $id = Noid::mint($noid, 'test@example.org');
            Db::dbclose($noid);
            if ($id) {
                $singleIds[] = $id;
            }
        }
        $endSingle = microtime(true);
        $singleTime = $endSingle - $startSingle;

        $this->assertCount($count, $singleIds);

        // Multiple should be faster (allow some tolerance)
        // Note: This test may occasionally fail due to system load variations
        $this->assertLessThan(
            $singleTime,
            $multipleTime,
            sprintf(
                'mintMultiple (%.4fs) should be faster than individual minting (%.4fs)',
                $multipleTime,
                $singleTime
            )
        );
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
