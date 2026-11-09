<?php

declare(strict_types=1);

namespace Doctrine\Common\Cache\Psr6;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\Clearable_Cache;
use Doctrine\Common\Cache\Multi_Delete_Cache;
use Doctrine\Common\Cache\Multi_Get_Cache;
use Doctrine\Common\Cache\Multi_Put_Cache;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Component\Cache\DoctrineProvider as SymfonyDoctrineProvider;

use const PHP_VERSION_ID;

use function array_key_exists;
use function assert;
use function count;
use function current;
use function get_class;
use function gettype;
use function is_object;
use function is_string;
use function microtime;
use function sprintf;
use function strpbrk;

final class Cache_Adapter implements CacheItemPoolInterface
{
    private const RESERVED_CHARACTERS = '{}()/\@:';

    /** @var Cache */
    private $cache;

    /** @var array<string, CacheItemInterface> */
    private array $deferredItems = [];

    public static function wrap(Cache $cache): CacheItemPoolInterface
    {
        if ($cache instanceof Doctrine_Provider && ! $cache->get_namespace()) {
            return $cache->get_pool();
        }

        if ($cache instanceof SymfonyDoctrineProvider && ! $cache->getNamespace()) {
            $getPool = function () {
                // phpcs:ignore Squiz.Scope.StaticThisUsage.Found
                return $this->pool;
            };

            return $getPool->bindTo($cache, SymfonyDoctrineProvider::class)();
        }

        return new self($cache);
    }

    private function __construct(Cache $cache)
    {
        $this->cache = $cache;
    }

    /** @internal */
    public function get_cache(): Cache
    {
        return $this->cache;
    }

    public function getItem(string $key): CacheItemInterface
    {
        assert(self::validKey($key));

        if (isset($this->deferredItems[$key])) {
            $this->commit();
        }

        $value = $this->cache->fetch($key);

        if (PHP_VERSION_ID >= 80000) {
            if ($value !== false) {
                return new Typed_Cache_Item($key, $value, true);
            }

            return new Typed_Cache_Item($key, null, false);
        }

        if ($value !== false) {
            return new Cache_Item($key, $value, true);
        }

        return new Cache_Item($key, null, false);
    }

    public function getItems(array $keys = []): iterable
    {
        if ($this->deferredItems) {
            $this->commit();
        }

        assert(self::validKeys($keys));

        $values = $this->doFetchMultiple($keys);
        $pairs  = [];

        if (PHP_VERSION_ID >= 80000) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $values)) {
                    $pairs[] = [$key, new Typed_Cache_Item($key, $values[$key], true)];
                } else {
                    $pairs[] = [$key, new Typed_Cache_Item($key, null, false)];
                }
            }
        } else {
            foreach ($keys as $key) {
                if (array_key_exists($key, $values)) {
                    $pairs[] = [$key, new Cache_Item($key, $values[$key], true)];
                } else {
                    $pairs[] = [$key, new Cache_Item($key, null, false)];
                }
            }
        }

        return new String_Keyed_Item_Iterable($pairs);
    }

    public function hasItem(string $key): bool
    {
        assert(self::validKey($key));

        if (isset($this->deferredItems[$key])) {
            $this->commit();
        }

        return $this->cache->contains($key);
    }

    public function clear(): bool
    {
        $this->deferredItems = [];

        if (! $this->cache instanceof Clearable_Cache) {
            return false;
        }

        return $this->cache->delete_all();
    }

    public function deleteItem(string $key): bool
    {
        assert(self::validKey($key));
        unset($this->deferredItems[$key]);

        return $this->cache->delete($key);
    }

    public function deleteItems(array $keys): bool
    {
        foreach ($keys as $key) {
            assert(self::validKey($key));
            unset($this->deferredItems[$key]);
        }

        return $this->doDeleteMultiple($keys);
    }

    public function save(CacheItemInterface $item): bool
    {
        return $this->saveDeferred($item) && $this->commit();
    }

    public function saveDeferred(CacheItemInterface $item): bool
    {
        if (! $item instanceof Cache_Item && ! $item instanceof Typed_Cache_Item) {
            return false;
        }

        $this->deferredItems[$item->getKey()] = $item;

        return true;
    }

    public function commit(): bool
    {
        if (! $this->deferredItems) {
            return true;
        }

        $now         = microtime(true);
        $itemsCount  = 0;
        $byLifetime  = [];
        $expiredKeys = [];
        $lastKey     = null;
        $lastItem    = null;
        $lastLifetime = 0;

        foreach ($this->deferredItems as $key => $item) {
            $lifetime = ($item->get_expiry() ?? $now) - $now;

            if ($lifetime < 0) {
                $expiredKeys[] = $key;
                continue;
            }

            ++$itemsCount;
            $byLifetime[(int) $lifetime][$key] = $item->get();
            $lastKey      = $key;
            $lastItem     = $item;
            $lastLifetime = (int) $lifetime;
        }

        $this->deferredItems = [];

        switch (count($expiredKeys)) {
            case 0:
                break;
            case 1:
                $this->cache->delete(current($expiredKeys));
                break;
            default:
                $this->doDeleteMultiple($expiredKeys);
                break;
        }

        if ($itemsCount === 1 && $lastKey !== null && $lastItem !== null) {
            return $this->cache->save($lastKey, $lastItem->get(), $lastLifetime);
        }

        $success = true;
        foreach ($byLifetime as $lifetime => $values) {
            $success = $this->doSaveMultiple($values, $lifetime) && $success;
        }

        return $success;
    }

    public function __destruct()
    {
        $this->commit();
    }

    /**
     * @param mixed $key
     */
    private static function validKey($key): bool
    {
        if (! is_string($key)) {
            throw new Invalid_Argument(sprintf('Cache key must be string, "%s" given.', is_object($key) ? get_class($key) : gettype($key)));
        }

        if ($key === '') {
            throw new Invalid_Argument('Cache key length must be greater than zero.');
        }

        if (strpbrk($key, self::RESERVED_CHARACTERS) !== false) {
            throw new Invalid_Argument(sprintf('Cache key "%s" contains reserved characters "%s".', $key, self::RESERVED_CHARACTERS));
        }

        return true;
    }

    /**
     * @param mixed[] $keys
     */
    private static function validKeys(array $keys): bool
    {
        foreach ($keys as $key) {
            self::validKey($key);
        }

        return true;
    }

    /**
     * @param mixed[] $keys
     */
    private function doDeleteMultiple(array $keys): bool
    {
        if ($this->cache instanceof Multi_Delete_Cache) {
            return $this->cache->delete_multiple($keys);
        }

        $success = true;
        foreach ($keys as $key) {
            $success = $this->cache->delete($key) && $success;
        }

        return $success;
    }

    /**
     * @param mixed[] $keys
     *
     * @return mixed[]
     */
    private function doFetchMultiple(array $keys): array
    {
        if ($this->cache instanceof Multi_Get_Cache) {
            return $this->cache->fetch_multiple($keys);
        }

        $values = [];
        foreach ($keys as $key) {
            $value = $this->cache->fetch($key);
            if ($value === false) {
                continue;
            }

            $values[$key] = $value;
        }

        return $values;
    }

    /**
     * @param mixed[] $keysAndValues
     */
    private function doSaveMultiple(array $keysAndValues, int $lifetime = 0): bool
    {
        if ($this->cache instanceof Multi_Put_Cache) {
            return $this->cache->save_multiple($keysAndValues, $lifetime);
        }

        $success = true;
        foreach ($keysAndValues as $key => $value) {
            $success = $this->cache->save($key, $value, $lifetime) && $success;
        }

        return $success;
    }
}
