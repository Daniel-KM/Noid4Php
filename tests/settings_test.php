<?php

// Use system temp directory for test data
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'noid_test';

return array(
    // The type of database may be lmdb, bdb, mysql, sqlite or xml.
    // Set to empty string '' to use 'lmdb' (the new default).
    // Use 'sqlite' or 'xml' for portable testing without external dependencies.
    // Use 'bdb' only on older systems with db4 handler available.
    'db_type' => 'lmdb',
    // Default storage in mainconfig is the root folder datafiles/.
    // It may be relative to the root.
    // Don't overwrite production dir!
    'storage' => array(
        'bdb' => array(
            'data_dir' => $tempDir,
            'db_name' => 'test_noid',
        ),
        'lmdb' => array(
            'data_dir' => $tempDir,
            'db_name' => 'test_noid',
        ),
        'mysql' => array(
            // Deprecated: use 'pdo' with 'driver' => 'mysql' instead.
            'data_dir' => $tempDir,
            // Server may be set with env vars NOID_TEST_DB_HOST, etc.
            'host' => getenv('NOID_TEST_DB_HOST') ?: 'localhost',
            'user' => getenv('NOID_TEST_DB_USER') ?: 'test_noid',
            'password' => getenv('NOID_TEST_DB_PASSWORD') ?: 'test_noid',
            'db_name' => 'test_noid',
            'port' => (int) (getenv('NOID_TEST_DB_PORT') ?: 3306),
            'socket' => null,
        ),
        'pdo' => array(
            // PDO storage backend - supports mysql, pgsql, sqlite drivers.
            // Use sqlite for testing as it requires no external server, or
            // set env var NOID_TEST_PDO_DRIVER to "mysql" to use the server
            // of the mysql backend.
            'driver' => getenv('NOID_TEST_PDO_DRIVER') ?: 'sqlite',
            'data_dir' => $tempDir,
            'host' => getenv('NOID_TEST_DB_HOST') ?: 'localhost',
            'port' => (int) (getenv('NOID_TEST_DB_PORT') ?: 3306),
            'user' => getenv('NOID_TEST_DB_USER') ?: 'test_noid',
            'password' => getenv('NOID_TEST_DB_PASSWORD') ?: 'test_noid',
            'db_name' => 'test_noid',
            'charset' => 'utf8mb4',
        ),
        'sqlite' => array(
            'data_dir' => $tempDir,
            'db_name' => 'test_noid',
        ),
        'xml' => array(
            'data_dir' => $tempDir,
            'db_name' => 'test_noid',
        ),
    ),
);
