<?php
/**
 * Tests for database migration functionality.
 *
 * This test suite covers:
 * - LMDB as the new default database type
 * - Conversion between LMDB and other formats
 * - Migration script functionality (import_from_dump.php)
 * - Parsing of db_dump and JSON formats
 *
 * @covers \Noid\Noid
 * @covers \Noid\Storage\LmdbDB
 * @covers \Noid\Lib\Db
 */

namespace NoidTest;

class DbMigrationTest extends NoidTestCase
{
    /**
     * Path to the migration script.
     *
     * @var string
     */
    protected $migrationScript;

    public function setUp(): void
    {
        parent::setUp();
        $this->migrationScript = dirname(dirname(__DIR__)) . '/scripts/import_from_dump.php';
    }

    /**
     * Test that LMDB handler is available.
     *
     * @coversNothing
     */
    public function testLmdbHandlerAvailable()
    {
        $this->assertTrue(
            extension_loaded('dba'),
            'Extension "dba" is not available.'
        );
        $this->assertTrue(
            in_array('lmdb', dba_handlers()),
            'LMDB handler is not available in DBA extension.'
        );
    }

    /**
     * Test LMDB is the new default database type.
     *
     * @coversNothing
     */
    public function testLmdbIsDefault()
    {
        // Test that the default settings file specifies lmdb
        $defaultConfig = dirname(dirname(__DIR__)) . '/config/settings.php';
        $this->assertFileExists($defaultConfig);
        $content = file_get_contents($defaultConfig);
        // The comment should indicate lmdb is the default
        $this->assertStringContainsString('lmdb', $content);
        $this->assertStringContainsString('The default is lmdb', $content);
    }

    /**
     * Test conversion from LMDB to other formats.
     *
     * @coversNothing
     * @throws \Exception
     */
    public function testDatabaseConvertingLmdb()
    {
        if (!in_array('lmdb', dba_handlers())) {
            $this->markTestSkipped('LMDB handler not available.');
        }

        $this->atomicConverting('lmdb', 'sqlite');
        $this->atomicConverting('lmdb', 'xml');

        // Only test mysql if available
        if (class_exists('mysqli')) {
            $this->atomicConverting('lmdb', 'mysql');
        }
    }

    /**
     * Test conversion to LMDB from other formats.
     *
     * @coversNothing
     * @throws \Exception
     */
    public function testDatabaseConvertingToLmdb()
    {
        if (!in_array('lmdb', dba_handlers())) {
            $this->markTestSkipped('LMDB handler not available.');
        }

        $this->atomicConverting('sqlite', 'lmdb');
        $this->atomicConverting('xml', 'lmdb');

        // Only test mysql if available
        if (class_exists('mysqli')) {
            $this->atomicConverting('mysql', 'lmdb');
        }
    }

    /**
     * Test that migration script exists and is executable.
     *
     * @coversNothing
     */
    public function testMigrationScriptExists()
    {
        $this->assertFileExists($this->migrationScript);
        $this->assertTrue(
            is_readable($this->migrationScript),
            'Migration script is not readable.'
        );
    }

    /**
     * Test migration script --check option.
     *
     * @coversNothing
     */
    public function testMigrationScriptCheck()
    {
        $cmd = sprintf('php %s --check 2>&1', escapeshellarg($this->migrationScript));
        $output = shell_exec($cmd);

        $this->assertStringContainsString('System Requirements Check', $output);
        $this->assertStringContainsString('LMDB handler', $output);
    }

    /**
     * Test migration script --help option.
     *
     * @coversNothing
     */
    public function testMigrationScriptHelp()
    {
        $cmd = sprintf('php %s --help 2>&1', escapeshellarg($this->migrationScript));
        $output = shell_exec($cmd);

        $this->assertStringContainsString('Restore a Noid database', $output);
        $this->assertStringContainsString('--json', $output);
        $this->assertStringContainsString('--check', $output);
    }

    /**
     * Test parsing of db_dump format.
     *
     * @coversNothing
     */
    public function testParseDumpFormat()
    {
        // Create a mock db_dump file
        $dumpContent = <<<'DUMP'
VERSION=3
format=print
type=btree
db_pagesize=4096
HEADER=END
 :noid/erc
 test_value
 key1
 value1
 key2
 value2
DATA=END
DUMP;

        $dumpFile = $this->data_dir . '/test_dump.txt';
        file_put_contents($dumpFile, $dumpContent);

        // Test dry-run parsing
        $cmd = sprintf(
            'php %s -n %s %s 2>&1',
            escapeshellarg($this->migrationScript),
            escapeshellarg($dumpFile),
            escapeshellarg($this->data_dir . '/test_noid')
        );
        $output = shell_exec($cmd);

        $this->assertStringContainsString('Found 3 records', $output);
        $this->assertStringContainsString('Dry run', $output);

        // Cleanup
        unlink($dumpFile);
    }

    /**
     * Test parsing of JSON format.
     *
     * @coversNothing
     */
    public function testParseJsonFormat()
    {
        // Create a mock JSON file
        $jsonData = [
            ':noid/erc' => 'test_value',
            'key1' => 'value1',
            'key2' => 'value2',
        ];

        $jsonFile = $this->data_dir . '/test_data.json';
        file_put_contents($jsonFile, json_encode($jsonData, JSON_PRETTY_PRINT));

        // Test dry-run parsing
        $cmd = sprintf(
            'php %s --json -n %s %s 2>&1',
            escapeshellarg($this->migrationScript),
            escapeshellarg($jsonFile),
            escapeshellarg($this->data_dir . '/test_noid')
        );
        $output = shell_exec($cmd);

        $this->assertStringContainsString('Found 3 records', $output);
        $this->assertStringContainsString('Dry run', $output);

        // Cleanup
        unlink($jsonFile);
    }

    /**
     * Test full migration from JSON to LMDB.
     *
     * @coversNothing
     */
    public function testFullMigrationJsonToLmdb()
    {
        if (!in_array('lmdb', dba_handlers())) {
            $this->markTestSkipped('LMDB handler not available.');
        }

        // First create a source database and mint some IDs
        $status = 0;
        $output = '';
        $errors = [];

        // Create source sqlite db
        $cmd = sprintf(
            "%s -f %s -t sqlite dbcreate > /dev/null",
            $this->cmd,
            escapeshellarg($this->settings_file)
        );
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        // Mint 5 IDs
        $cmd = sprintf(
            "%s -f %s -t sqlite mint 5",
            $this->cmd,
            escapeshellarg($this->settings_file)
        );
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, $errors);

        // Export sqlite data to JSON manually
        $sqliteFile = $this->data_dir . '/test_noid/noid.sqlite';
        $this->assertFileExists($sqliteFile);

        $db = new \SQLite3($sqliteFile, SQLITE3_OPEN_READONLY);
        $result = $db->query('SELECT k, v FROM noid');
        $data = [];
        while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
            $data[$row['k']] = $row['v'];
        }
        $db->close();

        $jsonFile = $this->data_dir . '/migration_test.json';
        file_put_contents($jsonFile, json_encode($data, JSON_PRETTY_PRINT));

        // Now use migration script to import to LMDB
        $lmdbDir = $this->data_dir . '/test_lmdb_migration';
        if (!is_dir($lmdbDir)) {
            mkdir($lmdbDir, 0775, true);
        }

        $cmd = sprintf(
            'php %s --json %s %s 2>&1',
            escapeshellarg($this->migrationScript),
            escapeshellarg($jsonFile),
            escapeshellarg($lmdbDir)
        );
        $migrationOutput = shell_exec($cmd);

        $this->assertStringContainsString('Successfully imported', $migrationOutput);
        $this->assertStringContainsString('Migration completed', $migrationOutput);

        // Verify LMDB file was created
        $lmdbFile = $lmdbDir . '/noid.lmdb';
        $this->assertFileExists($lmdbFile);

        // Verify data in LMDB
        $handle = dba_open($lmdbFile, 'r', 'lmdb');
        $this->assertNotFalse($handle, 'Failed to open LMDB file');

        $lmdbCount = 0;
        $key = dba_firstkey($handle);
        while ($key !== false) {
            $lmdbCount++;
            $key = dba_nextkey($handle);
        }
        dba_close($handle);

        $this->assertEquals(count($data), $lmdbCount, 'Record count mismatch after migration');

        // Cleanup
        unlink($jsonFile);
        // Remove all files in the lmdb directory
        $filesToClean = ['noid.lmdb', 'noid.lmdb-lock', 'log', 'loglmdb', 'README'];
        foreach ($filesToClean as $file) {
            $filePath = $lmdbDir . '/' . $file;
            if (file_exists($filePath)) {
                unlink($filePath);
            }
        }
        if (is_dir($lmdbDir)) {
            rmdir($lmdbDir);
        }
    }

    /**
     * Test that db_dump format with escaped characters is parsed correctly.
     *
     * @coversNothing
     */
    public function testParseDumpWithEscapedCharacters()
    {
        // Create a dump file with escaped characters
        $dumpContent = <<<'DUMP'
VERSION=3
format=print
type=btree
HEADER=END
 key\20with\20spaces
 value\20with\20spaces
 key\3awith\3acolons
 value\0anewline
DATA=END
DUMP;

        $dumpFile = $this->data_dir . '/test_escaped_dump.txt';
        file_put_contents($dumpFile, $dumpContent);

        // Test dry-run parsing with verbose
        $cmd = sprintf(
            'php %s -v -n %s %s 2>&1',
            escapeshellarg($this->migrationScript),
            escapeshellarg($dumpFile),
            escapeshellarg($this->data_dir . '/test_noid')
        );
        $output = shell_exec($cmd);

        $this->assertStringContainsString('Found 2 records', $output);
        // The key should have been decoded
        $this->assertStringContainsString('key with spaces', $output);

        // Cleanup
        unlink($dumpFile);
    }

    /**
     * Perform an atomic conversion test between two database types.
     *
     * @param string $src_type Source database type
     * @param string $dst_type Destination database type
     * @throws \Exception
     */
    protected function atomicConverting($src_type = 'lmdb', $dst_type = 'sqlite')
    {
        $status = 0;
        $output = '';
        $errors = [];

        // Create source db newly
        $cmd = sprintf(
            "%s -f %s -t %s dbcreate > /dev/null",
            $this->cmd,
            escapeshellarg($this->settings_file),
            $src_type
        );
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, "Failed to create $src_type database: " . $errors);

        // Mint 10 IDs (0-9) in source db
        $cmd = sprintf(
            "%s -f %s -t %s mint 10",
            $this->cmd,
            escapeshellarg($this->settings_file),
            $src_type
        );
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, "Failed to mint in $src_type: " . $errors);

        // Create destination db
        $cmd = sprintf(
            "%s -f %s -t %s dbcreate > /dev/null",
            $this->cmd,
            escapeshellarg($this->settings_file),
            $dst_type
        );
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, "Failed to create $dst_type database: " . $errors);

        // Import all data into destination db from source
        $cmd = sprintf(
            "%s -f %s -t %s dbimport %s > /dev/null",
            $this->cmd,
            escapeshellarg($this->settings_file),
            $dst_type,
            $src_type
        );
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, "Failed to import from $src_type to $dst_type: " . $errors);

        // Mint one more in destination db, and check its value
        $cmd = sprintf(
            "%s -f %s -t %s mint 1",
            $this->cmd,
            escapeshellarg($this->settings_file),
            $dst_type
        );
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status, "Failed to mint in $dst_type after import: " . $errors);

        // Extract the noid from output, ignoring any PHP warnings
        if (preg_match('/id:\s+(\S+)/', $output, $matches)) {
            $noid = $matches[1];
        } else {
            $noid = '';
        }
        $this->assertNotEmpty($noid, "No noid returned after minting in $dst_type. Output: $output");

        // The 11th ID should be "10"
        $this->assertEquals('10', $noid, "Expected noid '10' after importing 10 IDs from $src_type to $dst_type");
    }
}
