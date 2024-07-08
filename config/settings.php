<?php

return array(
    // The type of database may be lmdb, bdb, mysql, sqlite or xml.
    // The default is lmdb (Lightning Memory-Mapped Database), which is
    // available on all modern Linux distributions via php-dba.
    // Use 'bdb' only on older systems with db4 handler available.
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
