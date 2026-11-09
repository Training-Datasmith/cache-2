<?php

declare(strict_types=1);

namespace Doctrine\Tests\Common\Cache\Psr6;

use Cache\IntegrationTests\CachePoolTest;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\Psr6\Cache_Adapter;
use Doctrine\Common\Cache\Psr6\Doctrine_Provider;
use Doctrine\Tests\Common\Cache\Array_Cache;
use function array_key_exists;

use PHPUnit\Framework\Attributes\RequiresFunction;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\DoctrineProvider as SymfonyDoctrineProvider;

final class Cache_Adapter_Test extends CachePoolTest
{
    private ?Array_Cache $array_cache = null;

    public function createCachePool(): CacheItemPoolInterface
    {
        if (! $this->array_cache) {
            $this->array_cache = new Array_Cache();
        }

        return Cache_Adapter::wrap($this->array_cache);
    }

    public function testWithWrappedCache(): void
    {
        $rootCache = new ArrayAdapter();
        $wrapped   = Doctrine_Provider::wrap($rootCache);

        self::assertSame($rootCache, Cache_Adapter::wrap($wrapped));
    }

    #[RequiresFunction('Symfony\Component\Cache\DoctrineProvider::__construct')]
    public function testWithWrappedSymfonyCache(): void
    {
        $rootCache = new ArrayAdapter();
        $wrapped   = new SymfonyDoctrineProvider($rootCache);

        self::assertSame($rootCache, Cache_Adapter::wrap($wrapped));
    }

    public function testWithWrappedMinimalCache(): void
    {
        $rootCache = new class () implements Cache {
            /** @var mixed[] */
            public array $values = [];

            public function fetch($id)
            {
                return $this->values[$id] ?? false;
            }

            public function contains($id)
            {
                return array_key_exists($id, $this->values);
            }

            public function save($id, $data, $life_time = 0)
            {
                $this->values[$id] = $data;

                return true;
            }

            public function delete($id)
            {
                unset($this->values[$id]);

                return true;
            }

            public function get_stats()
            {
                return null;
            }
        };

        $adapter = Cache_Adapter::wrap($rootCache);
        self::assertInstanceOf(Cache_Adapter::class, $adapter);

        /** @var CacheItemInterface[] $items */
        $items = $adapter->getItems(['1', '2', '3']);
        self::assertCount(3, $items);
        foreach ($items as $key => $item) {
            $item->set($key);
            $adapter->saveDeferred($item);
        }

        self::assertTrue($adapter->commit());
        self::assertCount(3, $rootCache->values);

        self::assertFalse($adapter->clear());
        self::assertCount(3, $rootCache->values);

        self::assertTrue($adapter->deleteItems(['1', '2']));
        self::assertCount(1, $rootCache->values);
    }

    public function testItemsAreFlushedToTheUnderlyingCacheOnce(): void
    {
        $wrapped = $this->createMock(Cache::class);

        $adapter   = Cache_Adapter::wrap($wrapped);
        $cacheItem = $adapter->getItem('answer-to-life-universe-everything');
        $cacheItem->set(42);
        $adapter->saveDeferred($cacheItem);

        $wrapped->expects($this->once())
            ->method('save')
            ->willReturn(true);

        $adapter->commit();
        $adapter->commit();
    }

    public function testNamespacingFeatureIsPreservedWithDoctrineProvider(): void
    {
        $wrapped = new ArrayAdapter();

        $cacheApp1 = Doctrine_Provider::wrap($wrapped);
        $cacheApp1->set_namespace('app 1');

        $cacheApp2 = Doctrine_Provider::wrap($wrapped);
        $cacheApp2->set_namespace('app 2');

        $psrCacheApp1 = Cache_Adapter::wrap($cacheApp1);
        $psrCacheApp2 = Cache_Adapter::wrap($cacheApp2);

        $item = $psrCacheApp1->getItem('some key')->set('some value');
        $psrCacheApp1->save($item);
        self::assertFalse($psrCacheApp2->getItem('some key')->isHit());
    }

    #[RequiresFunction('Symfony\Component\Cache\DoctrineProvider::__construct')]
    public function testNamespacingFeatureIsPreservedWithSymfonyDoctrineProvider(): void
    {
        $wrapped = new ArrayAdapter();

        $cacheApp1 = new SymfonyDoctrineProvider($wrapped);
        $cacheApp1->setNamespace('app 1');

        $cacheApp2 = new SymfonyDoctrineProvider($wrapped);
        $cacheApp2->setNamespace('app 2');

        $psrCacheApp1 = Cache_Adapter::wrap($cacheApp1);
        $psrCacheApp2 = Cache_Adapter::wrap($cacheApp2);

        $item = $psrCacheApp1->getItem('some key')->set('some value');
        $psrCacheApp1->save($item);
        self::assertFalse($psrCacheApp2->getItem('some key')->isHit());
    }
}
