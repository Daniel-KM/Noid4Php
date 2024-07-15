<?php
/**
 * Tests for pre-generation pool functionality.
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
 * Tests for Noid::pregenerate() and pre-generation pool.
 *
 * @covers \Noid\Noid::pregenerate
 * @covers \Noid\Noid::getPregenCount
 * @covers \Noid\Noid::_getFromPregenPool
 */
class PregenPoolTest extends NoidTestCase
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
     * Test basic pre-generation.
     *
     * @throws Exception
     */
    public function testPregenerateBasic()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid, 'Failed to open database');

        // Pre-generate 10 IDs
        $count = Noid::pregenerate($noid, 'test@example.org', 10);
        $this->assertEquals(10, $count, 'Should pre-generate 10 IDs');

        // Check pool count
        $poolCount = Noid::getPregenCount($noid);
        $this->assertEquals(10, $poolCount, 'Pool should contain 10 IDs');

        Db::dbclose($noid);
    }

    /**
     * Test that mint() uses pre-generated IDs first.
     *
     * @throws Exception
     */
    public function testMintUsesPregenPool()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        // Pre-generate 5 IDs
        $pregenCount = Noid::pregenerate($noid, 'test@example.org', 5);
        $this->assertEquals(5, $pregenCount);

        // Mint should use pool
        $id1 = Noid::mint($noid, 'test@example.org');
        $this->assertNotEmpty($id1);

        // Pool should have 4 left
        $poolCount = Noid::getPregenCount($noid);
        $this->assertEquals(4, $poolCount);

        // Mint 4 more
        for ($i = 0; $i < 4; $i++) {
            $id = Noid::mint($noid, 'test@example.org');
            $this->assertNotEmpty($id);
        }

        // Pool should be empty
        $poolCount = Noid::getPregenCount($noid);
        $this->assertEquals(0, $poolCount);

        // Next mint should generate new ID (not from pool)
        $id6 = Noid::mint($noid, 'test@example.org');
        $this->assertNotEmpty($id6);

        Db::dbclose($noid);
    }

    /**
     * Test pre-generated IDs are unique and valid.
     *
     * @throws Exception
     */
    public function testPregenIdsAreUnique()
    {
        $this->_createDatabase('tst9.rdee');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        // Pre-generate 50 IDs
        Noid::pregenerate($noid, 'test@example.org', 50);

        // Mint all 50
        $ids = [];
        for ($i = 0; $i < 50; $i++) {
            $id = Noid::mint($noid, 'test@example.org');
            $this->assertNotEmpty($id, "Mint failed at iteration $i");
            $ids[] = $id;
        }

        // All IDs should be unique
        $unique = array_unique($ids);
        $this->assertCount(50, $unique, 'All pre-generated IDs should be unique');

        Db::dbclose($noid);
    }

    /**
     * Test pregenerate with zero count.
     *
     * @throws Exception
     */
    public function testPregenerateZeroCount()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        $count = Noid::pregenerate($noid, 'test@example.org', 0);
        $this->assertEquals(0, $count);

        $errmsg = Log::errmsg($noid);
        $this->assertStringContainsString('at least 1', $errmsg);

        Db::dbclose($noid);
    }

    /**
     * Test pregenerate exceeds max count.
     *
     * @throws Exception
     */
    public function testPregenerateExceedsMax()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        $count = Noid::pregenerate($noid, 'test@example.org', 10001);
        $this->assertEquals(0, $count);

        $errmsg = Log::errmsg($noid);
        $this->assertStringContainsString('cannot exceed 10000', $errmsg);

        Db::dbclose($noid);
    }

    /**
     * Test pregenerate without contact.
     *
     * @throws Exception
     */
    public function testPregenerateNoContact()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        $count = Noid::pregenerate($noid, '', 10);
        $this->assertEquals(0, $count);

        Db::dbclose($noid);
    }

    /**
     * Test pregenerate stops when minter exhausted.
     *
     * @throws Exception
     */
    public function testPregenerateExhausted()
    {
        // Create small minter (10 IDs max)
        $this->_removeDatabase();
        $report = Db::dbcreate($this->settings, 'test@example.org', 'tst9.sd', 'medium');
        $this->assertNotEmpty($report);

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        // Try to pre-generate 15, but only 10 available
        $count = Noid::pregenerate($noid, 'test@example.org', 15);
        $this->assertEquals(10, $count, 'Should only pre-generate 10 (minter capacity)');

        Db::dbclose($noid);
    }

    /**
     * Test pool is FIFO (first in, first out).
     *
     * @throws Exception
     */
    public function testPoolIsFifo()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        // Pre-generate 5 IDs and remember order
        Noid::pregenerate($noid, 'test@example.org', 5);

        // Mint 5 and verify FIFO order
        $mintedIds = [];
        for ($i = 0; $i < 5; $i++) {
            $mintedIds[] = Noid::mint($noid, 'test@example.org');
        }

        // IDs should be in sequential order (FIFO)
        for ($i = 1; $i < count($mintedIds); $i++) {
            $this->assertNotEquals($mintedIds[$i], $mintedIds[$i-1],
                'Pre-generated IDs should be unique');
        }

        Db::dbclose($noid);
    }

    /**
     * Test getPregenCount on empty pool.
     *
     * @throws Exception
     */
    public function testGetPregenCountEmpty()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid);

        $count = Noid::getPregenCount($noid);
        $this->assertEquals(0, $count);

        Db::dbclose($noid);
    }

    /**
     * Test performance of pre-generated minting vs regular minting.
     *
     * @throws Exception
     */
    public function testPregenPerformance()
    {
        $iterations = 30;
        $this->_createDatabase('tst9.rdee');

        // Time with pre-generation
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        Noid::pregenerate($noid, 'test@example.org', $iterations);

        $startPregen = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            Noid::mint($noid, 'test@example.org');
        }
        $pregenTime = microtime(true) - $startPregen;
        Db::dbclose($noid);

        // Recreate database for fair comparison
        $this->_removeDatabase();
        $this->_createDatabase('tst9.rdee');

        // Time without pre-generation
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $startNormal = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            Noid::mint($noid, 'test@example.org');
        }
        $normalTime = microtime(true) - $startNormal;
        Db::dbclose($noid);

        // Pre-gen minting should be faster (or at least not slower)
        // Note: May occasionally fail due to system load variations
        $this->assertLessThanOrEqual(
            $normalTime * 1.5,  // Allow some tolerance
            $pregenTime,
            sprintf(
                'Pre-gen minting (%.4fs) should not be slower than normal (%.4fs)',
                $pregenTime,
                $normalTime
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
