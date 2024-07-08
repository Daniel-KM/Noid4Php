<?php

namespace NoidTest;

/**
 * Tests for database conversion between different storage backends.
 *
 * @covers \Noid\Lib\Db
 */
class DbConvertTest extends NoidTestCase
{
    /**
     * Test that required PHP extensions are available.
     */
    public function testPhpExtensions()
    {
        $this->assertTrue(extension_loaded('dba'), 'Extension "dba" unavailable.');
        $this->assertTrue(extension_loaded('pdo'), 'Extension "pdo" unavailable.');
        $this->assertTrue(
            in_array('sqlite', \PDO::getAvailableDrivers()),
            'PDO SQLite driver unavailable.'
        );
        $this->assertTrue(class_exists('SQLite3'), 'Extension "sqlite3" unavailable.');
        $this->assertTrue(extension_loaded('xml'), 'Extension "xml" unavailable.');
    }

    /**
     * Test database conversion from LMDB to other formats.
     *
     * @throws \Exception
     */
    public function testDatabaseConvertingLmdb()
    {
        if (!in_array('lmdb', dba_handlers())) {
            $this->markTestSkipped('LMDB handler not available.');
        }

        $this->atomicConverting('lmdb', 'pdo');
        $this->atomicConverting('lmdb', 'sqlite');
        $this->atomicConverting('lmdb', 'xml');
    }

    /**
     * Test database conversion from PDO (SQLite) to other formats.
     *
     * @throws \Exception
     */
    public function testDatabaseConvertingPdo()
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers())) {
            $this->markTestSkipped('PDO SQLite driver not available.');
        }

        if (in_array('lmdb', dba_handlers())) {
            $this->atomicConverting('pdo', 'lmdb');
        }
        // Skip pdo -> sqlite: both use same noid.sqlite file when pdo driver is sqlite
        $this->atomicConverting('pdo', 'xml');
    }

    /**
     * Test database conversion from SQLite3 to other formats.
     *
     * @throws \Exception
     */
    public function testDatabaseConvertingSqlite()
    {
        if (!class_exists('SQLite3')) {
            $this->markTestSkipped('SQLite3 extension not available.');
        }

        if (in_array('lmdb', dba_handlers())) {
            $this->atomicConverting('sqlite', 'lmdb');
        }
        // Skip sqlite -> pdo: both use same noid.sqlite file when pdo driver is sqlite
        $this->atomicConverting('sqlite', 'xml');
    }

    /**
     * Test database conversion from XML to other formats.
     *
     * @throws \Exception
     */
    public function testDatabaseConvertingXml()
    {
        if (!extension_loaded('xml')) {
            $this->markTestSkipped('XML extension not available.');
        }

        if (in_array('lmdb', dba_handlers())) {
            $this->atomicConverting('xml', 'lmdb');
        }
        $this->atomicConverting('xml', 'sqlite');
        $this->atomicConverting('xml', 'pdo');
    }

    /**
     * Perform an atomic conversion test between two database types.
     *
     * @param string $src_type type of source database
     * @param string $dst_type type of destination database
     *
     * @throws \Exception
     */
    protected function atomicConverting($src_type = 'lmdb', $dst_type = 'pdo')
    {
        $status = 0;
        $output = '';
        $errors = array();

        // create source db newly.
        $cmd = sprintf("{$this->cmd} -f %s -t {$src_type} dbcreate > /dev/null", escapeshellarg($this->settings_file));
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, "Failed to create {$src_type} database: " . $errors);

        // Mint 10 ids (0-9) in source db.
        $cmd = sprintf("{$this->cmd} -f %s -t {$src_type} mint 10", escapeshellarg($this->settings_file));
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, "Failed to mint in {$src_type}: " . $errors);

        // create destination db.
        $cmd = sprintf("{$this->cmd} -f %s -t {$dst_type} dbcreate >/dev/null", escapeshellarg($this->settings_file));
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, "Failed to create {$dst_type} database: " . $errors);

        // import all data into destination db from source.
        $cmd = sprintf("{$this->cmd} -f %s -t {$dst_type} dbimport {$src_type} >/dev/null", escapeshellarg($this->settings_file));
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, "Failed to import from {$src_type} to {$dst_type}: " . $errors);

        // Mint one more in destination db, and check its value.
        $cmd = sprintf("{$this->cmd} -f %s -t {$dst_type} mint 1", escapeshellarg($this->settings_file));
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, "Failed to mint in {$dst_type} after import: " . $errors);

        // Extract the noid from output, ignoring any PHP warnings.
        if (preg_match('/id:\s+(\S+)/', $output, $matches)) {
            $noid = $matches[1];
        } else {
            $noid = '';
        }
        $this->assertNotEmpty($noid, "No noid returned after minting in {$dst_type}. Output: {$output}");

        // The 11th ID should be "10"
        $this->assertEquals('10', $noid, "Expected noid '10' after importing 10 IDs from {$src_type} to {$dst_type}");
    }
}
