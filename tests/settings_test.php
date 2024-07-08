<?php

// Use system temp directory for test data
$tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'noid_test';

return array(
    // The type of database may be lmdb, bdb, mysql, sqlite or xml.
    // Set to empty string '' to use 'lmdb' (the new default).
    // Use 'sqlite' or 'xml' for portable testing without external dependencies.
    // Use 'bdb' only on older systems with db4 handler available.
    'db_type' => 'sqlite',
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
            'host' => 'localhost',
            'user' => 'test_noid',
            'password' => 'test_noid',
            'db_name' => 'test_noid',
            'port' => 3306,
            'socket' => null,
        ),
        'pdo' => array(
            // PDO storage backend - supports mysql, pgsql, sqlite drivers.
            'driver' => 'mysql',
            'data_dir' => $tempDir,
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'test_noid',
            'password' => 'test_noid',
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
