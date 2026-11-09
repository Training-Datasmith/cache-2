<?php

declare(strict_types=1);

namespace Doctrine\Tests\Common\Cache;

use Doctrine\Common\Cache\Cache_Provider;
use Doctrine\Tests\Doctrine_Test_Case;

class Cache_Provider_Test extends Doctrine_Test_Case
{
    public function testFetchMultiWillFilterNonRequestedKeys(): void
    {
        $cache = $this->getMockBuilder(Array_Cache::class)
            ->onlyMethods(['do_fetch_multiple'])
            ->getMock();

        $cache
            ->expects($this->once())
            ->method('do_fetch_multiple')
            ->willReturn([
                '[foo][1]' => 'bar',
                '[bar][1]' => 'baz',
                '[baz][1]' => 'tab',
            ]);

        self::assertEquals(
            ['foo' => 'bar', 'bar' => 'baz'],
            $cache->fetch_multiple(['foo', 'bar'])
        );
    }

    public function testFailedDeleteAllDoesNotChangeNamespaceVersion(): void
    {
        $cache = $this->getMockBuilder(Array_Cache::class)
            ->onlyMethods(['do_fetch', 'do_save', 'do_contains'])
            ->getMock();

        $cache
            ->expects($this->once())
            ->method('do_fetch')
            ->with('DoctrineNamespaceCacheKey[]')
            ->willReturn(false);

        $cache
            ->expects($this->once())
            ->method('do_save')
            ->with('DoctrineNamespaceCacheKey[]')
            ->willReturn(false);

        $cache
            ->expects($this->once())
            ->method('do_contains')
            ->with('[key][1]')
            ->willReturn(true);

        self::assertFalse($cache->delete_all(), 'delete_all() returns false when saving the namespace version fails');
        $cache->contains('key');
    }

    public function testSaveMultipleNoFail(): void
    {
        $cache = $this->getMockBuilder(Array_Cache::class)
            ->onlyMethods(['do_save'])
            ->getMock();

        $calls = [];
        $cache
            ->method('do_save')
            ->willReturnCallback(static function (string $id, $data, $lifetime = 0) use (&$calls): bool {
                $calls[] = [$id, $data, $lifetime];

                return $id === '[kok][1]';
            });

        $cache->save_multiple([
            'kerr' => 'verr',
            'kok'  => 'vok',
        ]);

        self::assertSame(
            [
                ['[kerr][1]', 'verr', 0],
                ['[kok][1]', 'vok', 0],
            ],
            $calls
        );
    }

    public function testDeleteMultipleNoFail(): void
    {
        $cache = $this->getMockBuilder(Array_Cache::class)
            ->onlyMethods(['do_delete'])
            ->getMock();

        $calls = [];
        $cache
            ->method('do_delete')
            ->willReturnCallback(static function (string $id) use (&$calls): bool {
                $calls[] = $id;

                return $id === '[kok][1]';
            });

        $cache->delete_multiple(['kerr', 'kok']);

        self::assertSame(['[kerr][1]', '[kok][1]'], $calls);
    }

    public function testInvalidNamespaceVersionCacheEntry(): void
    {
        $cache = $this->getMockBuilder(Array_Cache::class)
            ->onlyMethods(['do_fetch', 'do_save'])
            ->getMock();

        $cache->expects($this->once())
            ->method('do_fetch')
            ->with('DoctrineNamespaceCacheKey[]')
            ->willReturn('corruptedStringKey');

        $cache->expects($this->once())
            ->method('do_save')
            ->with('DoctrineNamespaceCacheKey[]', 2, 0)
            ->willReturn(true);

        self::assertTrue($cache->delete_all());
    }
}
