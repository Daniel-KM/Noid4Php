<?php

namespace NoidTest;

use Noid\Storage\DatabaseInterface;
use Noid\Storage\LmdbDB;
use PHPUnit\Framework\TestCase;

/**
 * The default map size of the extension dba is 1 MB, so it is set by default.
 *
 * @covers \Noid\Storage\LmdbDB::open
 * @covers \Noid\Storage\LmdbDB::import
 */
class LmdbMapSizeTest extends TestCase
{
    /**
     * @var string
     */
    protected $dir;

    public function setUp(): void
    {
        if (!extension_loaded('dba') || !in_array('lmdb', dba_handlers())) {
            $this->markTestSkipped('LMDB handler not available.');
        }
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('The map size can be set since php 8.2.');
        }
        $this->dir = sys_get_temp_dir() . '/noid-lmdb-' . getmypid();
        @mkdir($this->dir, 0775, true);
    }

    public function tearDown(): void
    {
        foreach (glob($this->dir . '/NOID/*') ?: [] as $file) {
            unlink($file);
        }
        @rmdir($this->dir . '/NOID');
        @rmdir($this->dir);
    }

    public function testImportMoreThanTheDefaultMapSize(): void
    {
        // 600 values of 2 KB do not fit in 1 MB, and each write is slow.
        $data = [];
        for ($i = 0; $i < 600; ++$i) {
            $data['18469/' . base_convert((string) $i, 10, 36) . "\tlocations"] = str_repeat((string) $i . '|', intdiv(2048, strlen((string) $i) + 1));
        }
        $source = $this->createMock(DatabaseInterface::class);
        $source->method('get_range')->willReturn($data);

        $settings = [
            'db_type' => 'lmdb',
            'storage' => [
                'lmdb' => [
                    'data_dir' => $this->dir,
                    'db_name' => 'NOID',
                ],
            ],
        ];
        $storage = new LmdbDB();
        $storage->open($settings, DatabaseInterface::DB_CREATE);
        $this->assertTrue($storage->import($source));
        $storage->close();

        $storage->open($settings, DatabaseInterface::DB_RDONLY);
        $this->assertCount(600, $storage->get_range(''));
        $storage->close();
    }
}
