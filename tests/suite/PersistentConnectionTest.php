<?php
/**
 * Tests for persistent database connection functionality.
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
 * Tests for Db persistent connection mode.
 *
 * @covers \Noid\Lib\Db::dbpersist
 * @covers \Noid\Lib\Db::dbunpersist
 * @covers \Noid\Lib\Db::isPersistent
 * @covers \Noid\Lib\Db::isConnected
 * @covers \Noid\Lib\Db::getCurrentNoid
 */
class PersistentConnectionTest extends NoidTestCase
{
    const dbtype = 'lmdb';

    /**
     * Clean up after each test.
     */
    public function tearDown(): void
    {
        // Ensure persistent mode is disabled and connection is closed
        Db::dbunpersist();
        $this->_removeDatabase();
        parent::tearDown();
    }

    /**
     * Test enabling and disabling persistent mode.
     *
     * @throws Exception
     */
    public function testDbpersistToggle()
    {
        $this->assertFalse(Db::isPersistent(), 'Persistent mode should be disabled by default');

        Db::dbpersist(true);
        $this->assertTrue(Db::isPersistent(), 'Persistent mode should be enabled after dbpersist(true)');

        Db::dbpersist(false);
        $this->assertFalse(Db::isPersistent(), 'Persistent mode should be disabled after dbpersist(false)');
    }

    /**
     * Test that dbclose() does not close connection in persistent mode.
     *
     * @throws Exception
     */
    public function testDbcloseSkippedInPersistentMode()
    {
        $this->_createDatabase('tst9.rde');

        Db::dbpersist(true);
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid, 'Failed to open database');
        $this->assertTrue(Db::isConnected(), 'Should be connected after dbopen');

        // dbclose should NOT actually close the connection in persistent mode
        Db::dbclose($noid);
        $this->assertTrue(Db::isConnected(), 'Should still be connected after dbclose in persistent mode');

        // dbunpersist should actually close
        Db::dbunpersist($noid);
        $this->assertFalse(Db::isConnected(), 'Should be disconnected after dbunpersist');
    }

    /**
     * Test that dbopen() reuses existing connection in persistent mode.
     *
     * @throws Exception
     */
    public function testDbOpenReusesConnection()
    {
        $this->_createDatabase('tst9.rde');

        Db::dbpersist(true);

        // First open
        $noid1 = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertNotEmpty($noid1);
        $this->assertTrue(Db::isConnected());

        // Mint one ID
        $id1 = Noid::mint($noid1, 'test@example.org');
        $this->assertNotEmpty($id1);

        // Close (but it shouldn't actually close in persistent mode)
        Db::dbclose($noid1);

        // Second open - should reuse the connection
        $noid2 = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertEquals($noid1, $noid2, 'Should return same noid when reusing connection');

        // Mint another ID
        $id2 = Noid::mint($noid2, 'test@example.org');
        $this->assertNotEmpty($id2);
        $this->assertNotEquals($id1, $id2, 'Second mint should produce different ID');

        Db::dbunpersist();
    }

    /**
     * Test isConnected() method.
     *
     * @throws Exception
     */
    public function testIsConnected()
    {
        $this->_createDatabase('tst9.rde');

        $this->assertFalse(Db::isConnected(), 'Should not be connected initially');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertTrue(Db::isConnected(), 'Should be connected after dbopen');

        Db::dbclose($noid);
        $this->assertFalse(Db::isConnected(), 'Should not be connected after dbclose');
    }

    /**
     * Test getCurrentNoid() method.
     *
     * @throws Exception
     */
    public function testGetCurrentNoid()
    {
        $this->_createDatabase('tst9.rde');

        Db::dbpersist(true);

        $this->assertNull(Db::getCurrentNoid(), 'Should return null when not connected');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertEquals($noid, Db::getCurrentNoid(), 'Should return current noid when connected');

        Db::dbunpersist();
        $this->assertNull(Db::getCurrentNoid(), 'Should return null after dbunpersist');
    }

    /**
     * Test that persistent mode improves performance.
     *
     * @throws Exception
     */
    public function testPersistentModePerformance()
    {
        $iterations = 30;
        $this->_createDatabase('tst9.rdee');

        // Time without persistent mode (open/close each time)
        $startNonPersistent = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
            Noid::mint($noid, 'test@example.org');
            Db::dbclose($noid);
        }
        $nonPersistentTime = microtime(true) - $startNonPersistent;

        // Recreate database
        $this->_removeDatabase();
        $this->_createDatabase('tst9.rdee');

        // Time with persistent mode
        Db::dbpersist(true);
        $startPersistent = microtime(true);
        for ($i = 0; $i < $iterations; $i++) {
            $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
            Noid::mint($noid, 'test@example.org');
            Db::dbclose($noid);
        }
        $persistentTime = microtime(true) - $startPersistent;
        Db::dbunpersist();

        // Persistent mode should be faster
        $this->assertLessThan(
            $nonPersistentTime,
            $persistentTime,
            sprintf(
                'Persistent mode (%.4fs) should be faster than non-persistent (%.4fs)',
                $persistentTime,
                $nonPersistentTime
            )
        );
    }

    /**
     * Test that dbunpersist() closes connection and clears state.
     *
     * @throws Exception
     */
    public function testDbunpersist()
    {
        $this->_createDatabase('tst9.rde');

        Db::dbpersist(true);
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertTrue(Db::isPersistent());
        $this->assertTrue(Db::isConnected());

        Db::dbunpersist($noid);

        $this->assertFalse(Db::isPersistent(), 'Persistent mode should be disabled');
        $this->assertFalse(Db::isConnected(), 'Connection should be closed');
        $this->assertNull(Db::getCurrentNoid(), 'Current noid should be null');
    }

    /**
     * Test that force close works in persistent mode.
     *
     * @throws Exception
     */
    public function testForceClose()
    {
        $this->_createDatabase('tst9.rde');

        Db::dbpersist(true);
        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertTrue(Db::isConnected());

        // Force close should work even in persistent mode
        Db::dbclose($noid, true);  // force = true
        $this->assertFalse(Db::isConnected(), 'Force close should work in persistent mode');

        // Clean up persistent mode
        Db::dbpersist(false);
    }

    /**
     * Test that DB_CREATE always opens fresh connection.
     *
     * @throws Exception
     */
    public function testCreateModeBypassesPersistence()
    {
        $this->_createDatabase('tst9.rde');

        Db::dbpersist(true);
        $noid1 = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $id1 = Noid::mint($noid1, 'test@example.org');
        $this->assertNotEmpty($id1, 'First mint should succeed');

        // Force close the persistent connection before recreating
        Db::dbunpersist($noid1);

        // Create mode should create a fresh database
        $this->_removeDatabase();
        $report = Db::dbcreate($this->settings, 'test@example.org', 'tst9.rde', 'short');
        $this->assertNotEmpty($report, 'dbcreate should succeed');

        // Open again and verify we have a fresh database
        $noid2 = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $id2 = Noid::mint($noid2, 'test@example.org');
        $this->assertNotEmpty($id2, 'Second mint should succeed');

        // IDs should be the same since we recreated the database
        $this->assertEquals($id1, $id2, 'Fresh database should produce same first ID');

        Db::dbclose($noid2);
    }

    /**
     * Test multiple operations in persistent mode.
     *
     * @throws Exception
     */
    public function testMultipleOperationsInPersistentMode()
    {
        $this->_createDatabase('tst9.rdee');

        Db::dbpersist(true);

        $allIds = [];
        for ($i = 0; $i < 5; $i++) {
            $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
            $id = Noid::mint($noid, 'test@example.org');
            $this->assertNotEmpty($id, "Mint failed on iteration $i");
            $allIds[] = $id;
            Db::dbclose($noid);
        }

        // All IDs should be unique
        $unique = array_unique($allIds);
        $this->assertCount(5, $unique, 'All IDs should be unique across persistent connections');

        Db::dbunpersist();
    }

    /**
     * Test isOpen() method on storage engine.
     *
     * @throws Exception
     */
    public function testStorageEngineIsOpen()
    {
        $this->_createDatabase('tst9.rde');

        $noid = Db::dbopen($this->settings, DatabaseInterface::DB_WRITE);
        $this->assertTrue(Db::$engine->isOpen(), 'Engine should report open');

        Db::dbclose($noid);
        $this->assertFalse(Db::$engine->isOpen(), 'Engine should report closed');
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
