<?php

namespace NoidTest;

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'NoidTestCase.php';

class DbConvertTest extends NoidTestCase
{
    public function testPhpExtensions()
    {
        $this->assertEquals(true, extension_loaded('dba'), 'Extension "dba" unavailable: no BerkeleyDB.');
        $this->assertEquals(true, class_exists('mysqli'), 'Extension "mysql" or "mysqli" unavailable: no mysql.');
        $this->assertEquals(true, class_exists('SQLite3'), 'Extension "sqlite3" unavailable: no sqlite3.');
        $this->assertEquals(true, extension_loaded('xml'), 'Extension "xml" unavailable: no xml.');
    }

    /**
     * @throws \Exception
     */
    public function testDatabaseConvertingBerkeley()
    {
        $this->atomicConverting('bdb', 'mysql');
        $this->atomicConverting('bdb', 'sqlite');
        $this->atomicConverting('bdb', 'xml');
    }

    /**
     * @throws \Exception
     */
    public function testDatabaseConvertingMysql()
    {
        $this->atomicConverting('mysql', 'bdb');
        $this->atomicConverting('mysql', 'sqlite');
        $this->atomicConverting('mysql', 'xml');
    }

    /**
     * @throws \Exception
     */
    public function testDatabaseConvertingSqlite()
    {
        $this->atomicConverting('sqlite', 'bdb');
        $this->atomicConverting('sqlite', 'mysql');
        $this->atomicConverting('sqlite', 'xml');
    }

    /**
     * @throws \Exception
     */
    public function testDatabaseConvertingXml()
    {
        $this->atomicConverting('xml', 'bdb');
        $this->atomicConverting('xml', 'sqlite');
        $this->atomicConverting('xml', 'mysql');
    }

    /**
     * @param string $src_type type of source database
     * @param string $dst_type type of destination database
     *
     * @throws \Exception
     */
    protected function atomicConverting($src_type = 'bdb', $dst_type = 'mysql')
    {
        $status = 0;
        $output = '';
        $errors = array();

        // create source db newly.
        $cmd = "{$this->cmd} -f {$this->data_dir} -t {$src_type} dbcreate >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        // Mint 10 ids (0-9) in source db.
        $cmd = "{$this->cmd} -f {$this->data_dir} -t {$src_type} mint 10";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        // create destination db.
        $cmd = "{$this->cmd} -f {$this->data_dir} -t {$dst_type} dbcreate >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        // import all data into destination db from source.
        $cmd = "{$this->cmd} -f {$this->data_dir} -t {$dst_type} dbimport {$src_type} >/dev/null";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);

        // Mint one more in destination db, and check its value.
        $cmd = "{$this->cmd} -f {$this->data_dir} -t {$dst_type} mint 1";
        $this->_executeCommand($cmd, $status, $output, $errors);
        $this->assertEquals(0, $status);
        # Remove leading "Id: ".
        $noid = preg_replace('/^Id:\s+/', '', $output);
        $this->assertNotEmpty($noid);
        # echo '"Id: " precedes output of mint command for last noid';
        # Remove trailing white space.
        $noid = preg_replace('/\s+$/', '', $noid);
        $this->assertNotEmpty($noid);
        #is($noid, "10", "last noid was \"9\"");
        $this->assertEquals('10', $noid);
        # echo 'last noid was "10"';
    }
}
