<?php

return array(
    // The type of database may be bdb, mysql, sqlite or xml.
    // The default is bdb.
    'db_type' => '',
    // Default storage in mainconfig is the root folder datafiles/.
    // It may be relative to the root.
    // Don't overwrite production dir!
    'storage' => array(
        'bdb' => array(
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
