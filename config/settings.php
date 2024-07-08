<?php

return array(
    // The type of database may be lmdb, pdo, bdb, mysql, sqlite or xml.
    // The default is lmdb (Lightning Memory-Mapped Database), which is
    // available on all modern Linux distributions via php-dba.
    // Use 'pdo' for MySQL/PostgreSQL/SQLite via PDO (recommended for SQL).
    // Use 'bdb' only on older systems with db4 handler available.
    // Note: 'mysql' (mysqli) is deprecated, use 'pdo' with driver 'mysql'.
    'db_type' => '',
    // Default storage in main config is the root folder datafiles/.
    // It may be relative to the root.
    'storage' => array(
        'bdb' => array(
            // For compatibility with perl script, you may use `dirname(__DIR__)`.
            'data_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'datafiles',
            'db_name' => null,
        ),
        'lmdb' => array(
            // LMDB (Lightning Memory-Mapped Database) is Debian's recommended
            // replacement for BerkeleyDB. Requires php-dba with lmdb handler.
            'data_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'datafiles',
            'db_name' => null,
        ),
        'mysql' => array(
            // Deprecated: use 'pdo' with 'driver' => 'mysql' instead.
            'data_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'datafiles',
            'host' => null,
            'user' => null,
            'password' => null,
            'db_name' => null,
            'port' => null,
            'socket' => null,
        ),
        'pdo' => array(
            // PDO storage backend - supports mysql, pgsql, sqlite drivers.
            // This is the recommended backend for SQL databases.
            'driver' => 'mysql',  // 'mysql', 'pgsql', or 'sqlite'
            'data_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'datafiles',
            'host' => null,       // Not used for sqlite
            'port' => null,       // Default: 3306 (mysql), 5432 (pgsql)
            'user' => null,       // Not used for sqlite
            'password' => null,   // Not used for sqlite
            'db_name' => null,
            'charset' => 'utf8mb4',  // MySQL only
        ),
        'sqlite' => array(
            'data_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'datafiles',
            'db_name' => null,
        ),
        'xml' => array(
            'data_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'datafiles',
            'db_name' => null,
        ),
    ),
);
