<?php

declare(strict_types=1);

namespace Doctrine\Tests\Common\Cache;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\Cache_Provider;

class Array_Cache_Test extends Cache_Test
{
    protected function getCacheDriver(): Cache_Provider
    {
        return new Array_Cache();
    }

    public function testGetStats(): void
    {
        $cache = $this->getCacheDriver();
        $cache->fetch('test1');
        $cache->fetch('test2');
        $cache->fetch('test3');

        $cache->save('test1', 123);
        $cache->save('test2', 123);

        $cache->fetch('test1');
        $cache->fetch('test2');
        $cache->fetch('test3');

        $stats = $cache->get_stats();
        self::assertEquals(2, $stats[Cache::STATS_HITS]);
        self::assertEquals(5, $stats[Cache::STATS_MISSES]);
        self::assertNotNull($stats[Cache::STATS_UPTIME]);
        self::assertNull($stats[Cache::STATS_MEMORY_USAGE]);
        self::assertNull($stats[Cache::STATS_MEMORY_AVAILABLE]);

        $cache->delete('test1');
        $cache->delete('test2');

        $cache->fetch('test1');
        $cache->fetch('test2');
        $cache->fetch('test3');

        $stats = $cache->get_stats();
        self::assertEquals(2, $stats[Cache::STATS_HITS]);
        self::assertEquals(8, $stats[Cache::STATS_MISSES]);
    }

    protected function isSharedStorage(): bool
    {
        return false;
    }
}
