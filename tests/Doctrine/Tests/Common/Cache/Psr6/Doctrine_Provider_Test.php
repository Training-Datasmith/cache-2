<?php

declare(strict_types=1);

namespace Doctrine\Tests\Common\Cache\Psr6;

use Doctrine\Common\Cache\Cache_Provider;
use Doctrine\Common\Cache\Psr6\Cache_Adapter;
use Doctrine\Common\Cache\Psr6\Doctrine_Provider;
use Doctrine\Tests\Common\Cache\Array_Cache;
use Doctrine\Tests\Common\Cache\Cache_Test;
use PHPUnit\Framework\Attributes\RequiresFunction;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\DoctrineAdapter as SymfonyDoctrineAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

use function class_exists;
use function sprintf;
use function sys_get_temp_dir;

class Doctrine_Provider_Test extends Cache_Test
{
    protected function getCacheDriver(): Cache_Provider
    {
        $pool = new ArrayAdapter();

        return Doctrine_Provider::wrap($pool);
    }

    public function testProvider(): void
    {
        $cache = $this->getCacheDriver();

        $this->assertInstanceOf(Cache_Provider::class, $cache);

        $key = '{}()/\@:';

        $this->assertTrue($cache->delete($key));
        $this->assertFalse($cache->contains($key));

        $this->assertTrue($cache->save($key, 'bar'));
        $this->assertTrue($cache->contains($key));
        $this->assertSame('bar', $cache->fetch($key));

        $this->assertTrue($cache->delete($key));
        $this->assertFalse($cache->fetch($key));
        $this->assertTrue($cache->save($key, 'bar'));

        $cache->flush_all();
        $this->assertFalse($cache->fetch($key));
        $this->assertFalse($cache->contains($key));
    }

    public function testWithWrappedCache(): void
    {
        $rootCache = new Array_Cache();
        $wrapped   = Cache_Adapter::wrap($rootCache);

        self::assertSame($rootCache, Doctrine_Provider::wrap($wrapped));
    }

    public function testWithWrappedSymfonyCache(): void
    {
        if (! class_exists(SymfonyDoctrineAdapter::class)) {
            self::markTestSkipped('This test requires Symfony 5 or lower.');
        }

        $rootCache = new Array_Cache();
        $wrapped   = new SymfonyDoctrineAdapter($rootCache);

        self::assertSame($rootCache, Doctrine_Provider::wrap($wrapped));
    }

    public function testGetStats(): void
    {
        $this->markTestSkipped(sprintf('"%s" does not expose statistics', Doctrine_Provider::class));
    }

    public function testResetArrayAdapter(): void
    {
        $cache = $this->getCacheDriver();

        $cache->save('test', 'test');

        $cache->reset();

        $this->assertSame(false, $cache->fetch('test'));
    }

    public function testResetFilesystemAdapter(): void
    {
        $pool   = new FilesystemAdapter('', 0, sys_get_temp_dir() . '/doctrine-cache-test');
        $pool2  = new FilesystemAdapter('', 0, sys_get_temp_dir() . '/doctrine-cache-test');
        $cache  = Doctrine_Provider::wrap($pool);
        $cache2 = Doctrine_Provider::wrap($pool2);

        $cache->save('test', 'test');
        $cache->reset();

        $this->assertSame('test', $cache->fetch('test'));

        $cache2->delete_all();
        $this->assertSame('test', $cache->fetch('test'));
        $cache->reset();

        $this->assertSame(false, $cache->fetch('test'));
    }

    protected function isSharedStorage(): bool
    {
        return false;
    }
}
