<?php

namespace NoidTest;

use Noid\Lib\Db;
use Noid\Noid;
use Noid\Storage\DatabaseInterface;
use PHPUnit\Framework\TestCase;

/**
 * The generator of a new database does not depend on the previous operations.
 *
 * @covers \Noid\Lib\Db::dbcreate
 * @covers \Noid\Noid::mint
 */
class RandomGeneratorTest extends TestCase
{
    /**
     * @var string
     */
    protected $dir;

    public function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/noid-generator-' . getmypid();
        @mkdir($this->dir, 0775, true);
    }

    public function tearDown(): void
    {
        foreach (['mt_rand', 'default', 'next'] as $name) {
            @unlink($this->dir . '/' . $name . '/noid.xml');
            @unlink($this->dir . '/' . $name . '/log');
            @unlink($this->dir . '/' . $name . '/README');
            @rmdir($this->dir . '/' . $name);
        }
        @rmdir($this->dir);
    }

    public function testDefaultGeneratorDoesNotDependOnPreviousDatabase(): void
    {
        $generator = $this->create('mt_rand', 'mt_rand');
        $this->assertSame('mt_rand', $generator);

        // A database created without generator in the same process.
        $generator = $this->create('default', null);
        $this->assertSame(Noid::DEFAULT_RANDOM_GENERATOR, $generator);
        $this->assertSame('drand48', $generator);
    }

    public function testMintingUsesTheGeneratorOfTheDatabase(): void
    {
        $this->create('mt_rand', 'mt_rand');
        $mtRand = $this->mint('mt_rand');
        $this->create('default', null);
        $default = $this->mint('default');
        $this->assertNotSame($mtRand, $default);

        // The generator of the previous database is not used.
        $this->create('next', null);
        $this->assertSame($default, $this->mint('next'));
    }

    protected function create(string $name, ?string $generator): string
    {
        $settings = $this->settings($name);
        if ($generator) {
            $settings['generator'] = $generator;
        }
        Db::dbcreate($settings, 'test@example.org', 'b.rllllk', 'long', '99999', 'example.org', 'sub');
        $noid = Db::dbopen($settings, DatabaseInterface::DB_RDONLY);
        $value = Db::$engine->get(':/generator_random');
        Db::dbclose($noid);
        return $value;
    }

    protected function mint(string $name): string
    {
        $noid = Db::dbopen($this->settings($name), DatabaseInterface::DB_WRITE);
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = Noid::mint($noid, 'test@example.org');
        }
        Db::dbclose($noid);
        return implode(' ', $ids);
    }

    protected function settings(string $name): array
    {
        return [
            'db_type' => 'xml',
            'storage' => [
                'xml' => [
                    'data_dir' => $this->dir,
                    'db_name' => $name,
                ],
            ],
        ];
    }
}
