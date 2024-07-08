<?php

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
            'data_dir' => __DIR__ . DIRECTORY_SEPARATOR . 'datafiles_test',
            'db_name' => 'test_noid',
        ),
        'lmdb' => array(
            'data_dir' => __DIR__ . DIRECTORY_SEPARATOR . 'datafiles_test',
            'db_name' => 'test_noid',
        ),
        'mysql' => array(
            // This dir is used for logs.
            'data_dir' => __DIR__ . DIRECTORY_SEPARATOR . 'datafiles_test',
            // To set null allows to use default config of the server, except
            // the database name (mysqli config).
            // The default database name is "NOID", set in DabaseInterface.
            // Don't overwrite production database!
            'host' => 'localhost',
            'user' => 'test_noid',
            'password' => 'test_noid',
            'db_name' => 'test_noid',
            'port' => 3306,
            'socket' => null,
        ),
        'sqlite' => array(
            'data_dir' => __DIR__ . DIRECTORY_SEPARATOR . 'datafiles_test',
            'db_name' => 'test_noid',
        ),
        'xml' => array(
            'data_dir' => __DIR__ . DIRECTORY_SEPARATOR . 'datafiles_test',
            'db_name' => 'test_noid',
        ),
    ),
);
