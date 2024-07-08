<?php

return array(
    // The type of database may be bdb, lmdb, mysql, sqlite or xml.
    // The default is bdb (requires db4 handler which is unavailable on most
    // modern Linux distributions). Use 'lmdb', 'sqlite' or 'xml' instead.
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
            // This dir is used for logs.
            'data_dir' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'datafiles',
            // To set null allows to use default config of the server, except
            // the database name (mysqli config).
            // The default database name is "NOID", set in DabaseInterface.
            'host' => null,
            'user' => null,
            'password' => null,
            'db_name' => null,
            'port' => null,
            'socket' => null,
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
