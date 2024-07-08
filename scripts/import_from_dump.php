#!/usr/bin/env php
<?php
/**
 * Restore/import a Noid database from a db_dump text file into LMDB format.
 *
 * This is a RESTORE tool, not a migration tool:
 *   - It CREATES the LMDB database file directly (no need to run dbcreate first)
 *   - The dump file contains ALL Noid data (metadata, template, counters, bindings)
 *   - The restored database is fully functional
 *
 * This differs from `noid dbimport` which:
 *   - Requires the destination database to exist (created with dbcreate)
 *   - Requires the PHP db4 handler to read the source database
 *
 * Use this script when the PHP db4 handler is no longer available (Debian 12+, RHEL 10+)
 * and you need to restore/convert a BerkeleyDB database to LMDB format.
 *
 * Prerequisites:
 *   - db-util package for db_dump command (apt install db-util)
 *   - php-dba package with lmdb handler (apt install php-dba)
 *
 * Usage:
 *   1. First, dump your BerkeleyDB file using db_dump:
 *      db_dump -p /path/to/datafiles/NOID/noid.bdb > noid_dump.txt
 *
 *   2. Run this script:
 *      php import_from_dump.php noid_dump.txt /path/to/datafiles/NOID
 *
 *   Or with settings file:
 *      php import_from_dump.php noid_dump.txt -f /path/to/settings.php
 *
 * @author Daniel Berthereau (original Noid4Php)
 * @author Claude (migration script)
 * @license BSD
 */

namespace Noid\Scripts;

// Autoload Noid classes if available
$autoloadPaths = [
    __DIR__ . '/../vendor/autoload.php',
    __DIR__ . '/../../vendor/autoload.php',
    __DIR__ . '/../../../vendor/autoload.php',
];

$autoloaded = false;
foreach ($autoloadPaths as $autoloadPath) {
    if (file_exists($autoloadPath)) {
        require_once $autoloadPath;
        $autoloaded = true;
        break;
    }
}

use Exception;

/**
 * LMDB default maximum key size in bytes.
 * This is a compile-time constant in LMDB (MDB_MAXKEYSIZE = 511 by default).
 */
const LMDB_MAX_KEY_SIZE = 511;

/**
 * Check for keys that exceed LMDB's maximum key size.
 *
 * @param array $data Key-value pairs to check
 * @return array ['ok' => bool, 'oversized_keys' => array of key names, 'max_found' => int]
 */
function checkKeySize(array $data): array
{
    $oversizedKeys = [];
    $maxFound = 0;

    foreach ($data as $key => $value) {
        $keyLen = strlen($key);
        if ($keyLen > $maxFound) {
            $maxFound = $keyLen;
        }
        if ($keyLen > LMDB_MAX_KEY_SIZE) {
            $oversizedKeys[] = [
                'key' => strlen($key) > 80 ? substr($key, 0, 77) . '...' : $key,
                'length' => $keyLen,
            ];
        }
    }

    return [
        'ok' => empty($oversizedKeys),
        'oversized_keys' => $oversizedKeys,
        'max_found' => $maxFound,
    ];
}

/**
 * Create or update log files in the NOID directory.
 *
 * @param string $noidDir Path to the NOID directory
 * @param string $format Target format (e.g., 'lmdb')
 * @param string $sourceFile Source file used for import
 */
function createLogFiles(string $noidDir, string $format, string $sourceFile): void
{
    $noidDir = rtrim($noidDir, '/');
    $date = date('Y-m-d H:i:s');
    $version = class_exists('\Noid\Lib\Globals') ? \Noid\Lib\Globals::VERSION : 'unknown';

    // Main transaction log
    $logFile = "$noidDir/log";
    $logEntry = "$date Imported from $sourceFile by Noid4Php $version.\n";
    file_put_contents($logFile, $logEntry, FILE_APPEND);
    chmod($logFile, 0666);

    // Database engine log (loglmdb, logbdb, etc.)
    $dbLogFile = "$noidDir/log$format";
    if (!file_exists($dbLogFile)) {
        file_put_contents($dbLogFile, '');
        chmod($dbLogFile, 0666);
    }
}

/**
 * Update the README file in the NOID directory with conversion info.
 *
 * @param string $noidDir Path to the NOID directory
 * @param string $format Target format (e.g., 'lmdb')
 * @param string $sourceFile Source file used for import
 */
function updateReadme(string $noidDir, string $format, string $sourceFile): void
{
    $readmePath = rtrim($noidDir, '/') . '/README';
    $date = date('Y-m-d H:i:s');
    // Get version from Globals if available
    $version = class_exists('\Noid\Lib\Globals') ? \Noid\Lib\Globals::VERSION : 'unknown';
    $line1 = "Converted to $format on $date by Noid4Php $version.";
    $line2 = "Source: $sourceFile. Original noid.bdb kept as backup.";

    if (file_exists($readmePath)) {
        $content = file_get_contents($readmePath);
        $lines = explode("\n", $content);
        // Insert conversion note as second and third lines
        array_splice($lines, 1, 0, [$line1, $line2]);
        $newContent = implode("\n", $lines);
    } else {
        // Create new README with conversion note
        $newContent = "NOID database directory\n$line1\n$line2\n";
    }

    file_put_contents($readmePath, $newContent);
}

/**
 * Check system requirements and display helpful messages.
 *
 * @param bool $verbose Show detailed information
 * @return array ['ok' => bool, 'messages' => array]
 */
function checkRequirements(bool $verbose = false): array
{
    $messages = [];
    $errors = [];
    $ok = true;

    // Detect OS
    $os = PHP_OS_FAMILY;
    $isDebian = file_exists('/etc/debian_version');
    $isRedHat = file_exists('/etc/redhat-release');

    if ($verbose) {
        $messages[] = "System: $os" . ($isDebian ? ' (Debian/Ubuntu)' : ($isRedHat ? ' (RHEL/CentOS/Fedora)' : ''));
        $messages[] = "PHP version: " . PHP_VERSION;
    }

    // Check PHP DBA extension
    if (!extension_loaded('dba')) {
        $ok = false;
        $errors[] = "PHP DBA extension is not installed.";
        if ($isDebian) {
            $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
            $errors[] = "  Install with: sudo apt install php$phpVersion-dba";
            $errors[] = "  Or: sudo apt install php-dba";
        } elseif ($isRedHat) {
            $errors[] = "  Install with: sudo dnf install php-dba";
        } else {
            $errors[] = "  Install the php-dba package for your distribution.";
        }
    } else {
        if ($verbose) {
            $messages[] = "PHP DBA extension: OK";
        }

        // Check available handlers
        $handlers = dba_handlers();
        if ($verbose) {
            $messages[] = "Available DBA handlers: " . implode(', ', $handlers);
        }

        // Check LMDB handler
        if (!in_array('lmdb', $handlers)) {
            $ok = false;
            $errors[] = "LMDB handler is not available in PHP DBA.";
            if ($isDebian) {
                $errors[] = "  The php-dba package should include LMDB support by default.";
                $errors[] = "  Check with: php -r \"print_r(dba_handlers());\"";
                $errors[] = "  If missing, you may need to reinstall php-dba or check liblmdb0 package.";
                $errors[] = "  Install liblmdb: sudo apt install liblmdb0";
            } elseif ($isRedHat) {
                $errors[] = "  LMDB should be enabled by default in RHEL/Fedora php-dba.";
                $errors[] = "  Check with: php -r \"print_r(dba_handlers());\"";
            }
        } else {
            if ($verbose) {
                $messages[] = "LMDB handler: OK";
            }
        }

        // Check db4 handler (informational)
        if ($verbose) {
            if (in_array('db4', $handlers)) {
                $messages[] = "db4 handler: Available (you can use native dbimport instead)";
            } else {
                $messages[] = "db4 handler: Not available (this is expected on Debian 12+/RHEL 10+)";
            }
        }
    }

    // Check db_dump availability
    $dbDumpPath = trim(shell_exec('which db_dump 2>/dev/null') ?? '');
    if (empty($dbDumpPath)) {
        // Not a fatal error - user might have already created the dump file
        $messages[] = "db_dump command: Not found (needed to create dump files)";
        if ($isDebian) {
            $messages[] = "  Install with: sudo apt install db-util";
        } elseif ($isRedHat) {
            $messages[] = "  Install with: sudo dnf install libdb-utils";
        }
    } else {
        if ($verbose) {
            $messages[] = "db_dump command: $dbDumpPath";
            // Get version
            $version = trim(shell_exec('db_dump -V 2>&1 | head -1') ?? '');
            if ($version) {
                $messages[] = "  Version: $version";
            }
        }
    }

    return [
        'ok' => $ok,
        'messages' => $messages,
        'errors' => $errors,
    ];
}

/**
 * Display installation instructions for creating a dump file.
 */
function showDumpInstructions(): void
{
    echo <<<INSTRUCTIONS

=== How to create a dump file from BerkeleyDB ===

If you haven't created a dump file yet, follow these steps:

1. Install db-util package (provides db_dump command):

   Debian/Ubuntu:
     sudo apt install db-util

   RHEL/CentOS/Fedora:
     sudo dnf install libdb-utils

2. Create the dump file:

   db_dump -p /path/to/datafiles/NOID/noid.bdb > noid_dump.txt

   The -p option outputs in "printable" format (human-readable).

3. Verify the dump file:

   head -20 noid_dump.txt

   You should see something like:
     VERSION=3
     format=print
     type=btree
     ...
     HEADER=END
      :noid/...
      value...

4. If db_dump fails with version mismatch:

   The db_dump version must match the BerkeleyDB version used to create
   the database. On Debian, db-util uses version 5.3.

   If your database was created with a different version:
   - Use a Docker container with the correct version
   - Or use db_upgrade first: db_upgrade /path/to/noid.bdb

=== Alternative: Using Python ===

If db_dump doesn't work, you can use Python with the bsddb3 module:

1. Install Python bsddb3:

   Debian/Ubuntu:
     sudo apt install python3-bsddb3

   Or via pip (may require libdb-dev):
     pip3 install bsddb3

2. Create a Python script to export data:

   #!/usr/bin/env python3
   import bsddb3.db as db
   import json

   bdb = db.DB()
   bdb.open('/path/to/datafiles/NOID/noid.bdb', None, db.DB_BTREE, db.DB_RDONLY)

   cursor = bdb.cursor()
   data = {}
   rec = cursor.first()
   while rec:
       key, value = rec
       data[key.decode('utf-8', errors='replace')] = value.decode('utf-8', errors='replace')
       rec = cursor.next()

   cursor.close()
   bdb.close()

   with open('noid_data.json', 'w') as f:
       json.dump(data, f)

   print(f"Exported {len(data)} records to noid_data.json")

3. Then use this script with --json option to import the JSON file:

   php import_from_dump.php --json noid_data.json /path/to/datafiles/NOID

   A ready-to-use Python script is also provided: export_bdb_to_json.py

INSTRUCTIONS;
}

/**
 * Parse a JSON file exported from Python script.
 *
 * @param string $filename Path to the JSON file
 * @return array Associative array of key => value pairs
 * @throws Exception
 */
function parseJsonFile(string $filename): array
{
    if (!file_exists($filename)) {
        throw new Exception("JSON file not found: $filename");
    }

    $content = file_get_contents($filename);
    if ($content === false) {
        throw new Exception("Cannot read JSON file: $filename");
    }

    $data = json_decode($content, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception("Invalid JSON: " . json_last_error_msg());
    }

    if (!is_array($data)) {
        throw new Exception("JSON file must contain an object/associative array");
    }

    return $data;
}

/**
 * Parse a db_dump -p output file and return key-value pairs.
 *
 * The db_dump -p format is:
 *   VERSION=3
 *   format=print
 *   type=btree
 *   ...
 *   HEADER=END
 *    key1
 *    value1
 *    key2
 *    value2
 *   DATA=END
 *
 * Each key and value line starts with a single space.
 * Special characters are escaped as \xx (hex).
 *
 * @param string $filename Path to the dump file
 * @return array Associative array of key => value pairs
 * @throws Exception
 */
function parseDumpFile(string $filename): array
{
    if (!file_exists($filename)) {
        throw new Exception("Dump file not found: $filename");
    }

    $content = file_get_contents($filename);
    if ($content === false) {
        throw new Exception("Cannot read dump file: $filename");
    }

    $lines = explode("\n", $content);
    $data = [];
    $inData = false;
    $isKey = true;
    $currentKey = null;

    foreach ($lines as $line) {
        // Skip until we find HEADER=END
        if (!$inData) {
            if (trim($line) === 'HEADER=END') {
                $inData = true;
            }
            continue;
        }

        // Stop at DATA=END
        if (trim($line) === 'DATA=END') {
            break;
        }

        // Skip empty lines
        if ($line === '') {
            continue;
        }

        // Each data line starts with a space
        if (strlen($line) > 0 && $line[0] === ' ') {
            $value = substr($line, 1);
            // Decode escaped characters (\xx hex format)
            $value = decodeDbDumpValue($value);

            if ($isKey) {
                $currentKey = $value;
                $isKey = false;
            } else {
                if ($currentKey !== null) {
                    $data[$currentKey] = $value;
                }
                $currentKey = null;
                $isKey = true;
            }
        }
    }

    return $data;
}

/**
 * Decode db_dump escaped values.
 *
 * db_dump -p uses \xx format for non-printable characters,
 * where xx is a two-digit hexadecimal number.
 *
 * @param string $value The escaped value
 * @return string The decoded value
 */
function decodeDbDumpValue(string $value): string
{
    // Replace \xx hex sequences with actual characters
    return preg_replace_callback(
        '/\\\\([0-9a-fA-F]{2})/',
        function ($matches) {
            return chr(hexdec($matches[1]));
        },
        $value
    );
}

/**
 * Import data into LMDB using PHP DBA extension directly.
 *
 * @param array $data Key-value pairs to import
 * @param string $lmdbPath Path to the LMDB file (will be created if not exists)
 * @return int Number of records imported
 * @throws Exception
 */
function importToLmdbDirect(array $data, string $lmdbPath): int
{
    // Check DBA extension
    if (!extension_loaded('dba')) {
        throw new Exception('PHP DBA extension is not loaded.');
    }

    // Check LMDB handler
    if (!in_array('lmdb', dba_handlers())) {
        throw new Exception('LMDB handler is not available in PHP DBA extension.');
    }

    // Create directory if needed
    $dir = dirname($lmdbPath);
    if (!is_dir($dir)) {
        if (!mkdir($dir, 0775, true)) {
            throw new Exception("Cannot create directory: $dir");
        }
    }

    // Remove existing file to start fresh
    if (file_exists($lmdbPath)) {
        unlink($lmdbPath);
    }
    // Remove lock file if exists
    if (file_exists($lmdbPath . '-lock')) {
        unlink($lmdbPath . '-lock');
    }

    // Open LMDB in create mode
    $handle = @dba_open($lmdbPath, 'c', 'lmdb');
    if (!$handle) {
        throw new Exception("Cannot create LMDB database: $lmdbPath");
    }

    $count = 0;
    foreach ($data as $key => $value) {
        if (dba_replace($key, (string)$value, $handle)) {
            $count++;
        } else {
            fwrite(STDERR, "Warning: Failed to insert key: $key\n");
        }
    }

    dba_sync($handle);
    dba_close($handle);

    return $count;
}

/**
 * Import data using Noid4Php classes if available.
 *
 * @param array $data Key-value pairs to import
 * @param array $settings Noid settings
 * @return int Number of records imported
 * @throws Exception
 */
function importToLmdbNoid(array $data, array $settings): int
{
    if (!class_exists('\Noid\Storage\LmdbDB')) {
        throw new Exception('Noid4Php classes not available.');
    }

    $settings['db_type'] = 'lmdb';
    $storage = new \Noid\Storage\LmdbDB();

    // Open in create mode
    if (!$storage->open($settings, \Noid\Storage\DatabaseInterface::DB_CREATE)) {
        throw new Exception('Cannot create LMDB database.');
    }

    $count = 0;
    foreach ($data as $key => $value) {
        if ($storage->set($key, $value)) {
            $count++;
        } else {
            fwrite(STDERR, "Warning: Failed to insert key: $key\n");
        }
    }

    $storage->close();

    return $count;
}

/**
 * Display usage information.
 */
function showUsage(): void
{
    $script = basename(__FILE__);
    echo <<<USAGE
Restore a Noid database from db_dump output into LMDB format.

This is a RESTORE tool that CREATES the LMDB database directly.
No need to run 'noid dbcreate' first - the dump contains all Noid metadata.

Usage:
  $script <dump_file> <lmdb_directory>
  $script <dump_file> -f <settings_file>
  $script --check
  $script --dump-help

Arguments:
  dump_file       Path to the db_dump -p output file (or JSON with --json)
  lmdb_directory  Path to the NOID directory (e.g., /path/to/datafiles/NOID)
                  The noid.lmdb file will be CREATED in this directory.
  settings_file   Path to Noid4Php settings.php file

Examples:
  # Check system requirements first:
  $script --check

  # Create the dump file (requires db-util package):
  db_dump -p /path/to/datafiles/NOID/noid.bdb > noid_dump.txt

  # Restore to LMDB (creates noid.lmdb):
  $script noid_dump.txt /path/to/datafiles/NOID

  # Or using settings file:
  $script noid_dump.txt -f settings.php

  # From JSON (exported via Python/Perl script):
  $script --json noid_data.json /path/to/datafiles/NOID

Options:
  -h, --help       Show this help message
  -v, --verbose    Show detailed progress
  -n, --dry-run    Parse dump file but don't import (shows record count)
  -c, --check      Check system requirements (PHP DBA, LMDB handler, db_dump)
  --dump-help      Show instructions for creating a dump file
  --json           Input file is JSON format (from Python/Perl export script)

Note: This differs from 'noid dbimport' which requires both source and
destination databases to be accessible via PHP handlers.

USAGE;
}

/**
 * Main function.
 */
function main(array $argv): int
{
    // Parse arguments
    $dumpFile = null;
    $targetPath = null;
    $settingsFile = null;
    $verbose = false;
    $dryRun = false;
    $checkOnly = false;
    $showDumpHelp = false;
    $isJson = false;

    $args = array_slice($argv, 1);
    $positional = [];

    for ($i = 0; $i < count($args); $i++) {
        $arg = $args[$i];
        switch ($arg) {
            case '-h':
            case '--help':
                showUsage();
                return 0;
            case '-v':
            case '--verbose':
                $verbose = true;
                break;
            case '-n':
            case '--dry-run':
                $dryRun = true;
                break;
            case '-c':
            case '--check':
                $checkOnly = true;
                break;
            case '--dump-help':
                $showDumpHelp = true;
                break;
            case '--json':
                $isJson = true;
                break;
            case '-f':
                if (!isset($args[$i + 1])) {
                    fwrite(STDERR, "Error: -f requires a settings file path\n");
                    return 1;
                }
                $settingsFile = $args[++$i];
                break;
            default:
                if (strlen($arg) > 0 && $arg[0] !== '-') {
                    $positional[] = $arg;
                } else {
                    fwrite(STDERR, "Unknown option: $arg\n");
                    return 1;
                }
        }
    }

    // Handle --check option
    if ($checkOnly) {
        echo "=== System Requirements Check ===\n\n";
        $check = checkRequirements(true);

        foreach ($check['messages'] as $msg) {
            echo "$msg\n";
        }

        if (!empty($check['errors'])) {
            echo "\nErrors:\n";
            foreach ($check['errors'] as $err) {
                fwrite(STDERR, "  $err\n");
            }
            echo "\n";
            return 1;
        }

        echo "\nAll requirements satisfied!\n";
        return 0;
    }

    // Handle --dump-help option
    if ($showDumpHelp) {
        showDumpInstructions();
        return 0;
    }

    // Check requirements before proceeding
    $check = checkRequirements(false);
    if (!$check['ok']) {
        echo "System requirements not met:\n";
        foreach ($check['errors'] as $err) {
            fwrite(STDERR, "  $err\n");
        }
        echo "\nRun with --check for detailed information.\n";
        return 1;
    }

    // Validate arguments
    if (count($positional) < 1) {
        showUsage();
        return 1;
    }

    $dumpFile = $positional[0];

    if ($settingsFile) {
        if (!file_exists($settingsFile)) {
            fwrite(STDERR, "Settings file not found: $settingsFile\n");
            return 1;
        }
    } elseif (count($positional) >= 2) {
        $targetPath = $positional[1];
    } else {
        fwrite(STDERR, "Error: Either specify target directory or use -f with settings file\n");
        showUsage();
        return 1;
    }

    // Parse input file (dump or JSON)
    $fileType = $isJson ? 'JSON' : 'dump';
    echo "Parsing $fileType file: $dumpFile\n";
    try {
        if ($isJson) {
            $data = parseJsonFile($dumpFile);
        } else {
            $data = parseDumpFile($dumpFile);
        }
    } catch (Exception $e) {
        fwrite(STDERR, "Error parsing $fileType file: " . $e->getMessage() . "\n");
        return 1;
    }

    $recordCount = count($data);
    echo "Found $recordCount records\n";

    // Check for oversized keys that exceed LMDB's limit
    $keyCheck = checkKeySize($data);
    if ($verbose) {
        echo "Maximum key size found: {$keyCheck['max_found']} bytes (LMDB limit: " . LMDB_MAX_KEY_SIZE . ")\n";
    }

    if (!$keyCheck['ok']) {
        $oversizedCount = count($keyCheck['oversized_keys']);
        fwrite(STDERR, "\nError: Found $oversizedCount key(s) exceeding LMDB's maximum key size (" . LMDB_MAX_KEY_SIZE . " bytes).\n\n");
        fwrite(STDERR, "Oversized keys:\n");
        foreach (array_slice($keyCheck['oversized_keys'], 0, 10) as $item) {
            fwrite(STDERR, "  - {$item['key']} ({$item['length']} bytes)\n");
        }
        if ($oversizedCount > 10) {
            fwrite(STDERR, "  ... and " . ($oversizedCount - 10) . " more\n");
        }
        fwrite(STDERR, "\nLMDB cannot store keys larger than " . LMDB_MAX_KEY_SIZE . " bytes.\n");
        fwrite(STDERR, "Use a different storage backend instead:\n");
        fwrite(STDERR, "  - sqlite: No practical key size limit\n");
        fwrite(STDERR, "  - xml: No practical key size limit\n");
        fwrite(STDERR, "  - mysql/pdo: Depends on configuration, typically larger limits\n");
        fwrite(STDERR, "\nTo import into SQLite instead, use the noid tool:\n");
        fwrite(STDERR, "  1. First create an LMDB database without the oversized keys, or\n");
        fwrite(STDERR, "  2. Use 'noid -t sqlite dbcreate' and import via custom script\n\n");
        return 1;
    }

    if ($verbose) {
        echo "\nFirst 10 keys:\n";
        $i = 0;
        foreach ($data as $key => $value) {
            if ($i++ >= 10) break;
            $displayValue = strlen($value) > 50 ? substr($value, 0, 50) . '...' : $value;
            echo "  $key => $displayValue\n";
        }
        echo "\n";
    }

    if ($dryRun) {
        echo "Dry run - no data imported.\n";
        return 0;
    }

    // Import to LMDB
    echo "Importing to LMDB...\n";
    try {
        if ($settingsFile) {
            $settings = include $settingsFile;
            $imported = importToLmdbNoid($data, $settings);
            $lmdbPath = $settings['storage']['lmdb']['data_dir'] . '/' .
                       ($settings['storage']['lmdb']['db_name'] ?? 'NOID') . '/noid.lmdb';
        } else {
            $lmdbPath = rtrim($targetPath, '/') . '/noid.lmdb';
            $imported = importToLmdbDirect($data, $lmdbPath);
        }
    } catch (Exception $e) {
        fwrite(STDERR, "Error importing data: " . $e->getMessage() . "\n");
        return 1;
    }

    echo "Successfully imported $imported records to: $lmdbPath\n";

    // Verify
    echo "\nVerifying import...\n";
    if (!extension_loaded('dba') || !in_array('lmdb', dba_handlers())) {
        echo "Warning: Cannot verify - LMDB handler not available\n";
        return 0;
    }

    $handle = @dba_open($lmdbPath, 'r', 'lmdb');
    if (!$handle) {
        fwrite(STDERR, "Warning: Cannot open LMDB for verification\n");
        return 0;
    }

    $verifyCount = 0;
    $key = dba_firstkey($handle);
    while ($key !== false) {
        $verifyCount++;
        $key = dba_nextkey($handle);
    }
    dba_close($handle);

    echo "Verification: $verifyCount records in LMDB database\n";

    if ($verifyCount !== $imported) {
        fwrite(STDERR, "Warning: Record count mismatch!\n");
        return 1;
    }

    // Update README and create log files
    $noidDir = dirname($lmdbPath);
    updateReadme($noidDir, 'lmdb', basename($dumpFile));
    createLogFiles($noidDir, 'lmdb', basename($dumpFile));
    echo "Updated README and log files\n";

    echo "\nMigration completed successfully!\n";
    echo "\nNext steps:\n";
    echo "1. Update your settings.php to use 'db_type' => 'lmdb'\n";
    echo "2. Test with: ./noid -t lmdb dbinfo\n";
    echo "3. Keep a backup of your original .bdb file\n";

    return 0;
}

// Run main function
exit(main($argv));
