<?php

declare (strict_types=1);
namespace Doctrine\Common\Cache\Psr6;

use function array_key_exists;
use function assert;
use function count;
use function current;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\Clearable_Cache;
use Doctrine\Common\Cache\Multi_Delete_Cache;
use Doctrine\Common\Cache\Multi_Get_Cache;
use Doctrine\Common\Cache\Multi_Put_Cache;
use function get_class;
use function gettype;
use function is_object;
use function is_string;
use function microtime;
use const PHP_VERSION_ID;
use Psr\Cache\Cache_Item_Interface;
use Psr\Cache\Cache_Item_Pool_Interface;
use function sprintf;
use function strpbrk;
use Symfony\Component\Cache\Doctrine_Provider as SymfonyDoctrineProvider;
final class Cache_Adapter implements Cache_Item_Pool_Interface
{
    private const RESERVED_CHARACTERS = '{}()/\@:';
    /** @var Cache */
    private $cache;
    /** @var array<CacheItem|TypedCacheItem> */
    private $deferred_items = [];
    public static function wrap(Cache $cache): Cache_Item_Pool_Interface
    {
        if ($cache instanceof Doctrine_Provider && !$cache->get_namespace()) {
            return $cache->get_pool();
        }
        if ($cache instanceof Symfony_Doctrine_Provider && !$cache->get_namespace()) {
            $get_pool = function () {
                // phpcs:ignore Squiz.Scope.StaticThisUsage.Found
                return $this->pool;
            };
            return $get_pool->bind_to($cache, Symfony_Doctrine_Provider::class)();
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
    /**
     * {@inheritDoc}
     */
    public function get_item($key): Cache_Item_Interface
    {
        assert(self::valid_key($key));
        if (isset($this->deferred_items[$key])) {
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
    /**
     * {@inheritDoc}
     */
    public function get_items(array $keys = []): array
    {
        if ($this->deferred_items) {
            $this->commit();
        }
        assert(self::valid_keys($keys));
        $values = $this->do_fetch_multiple($keys);
        $items = [];
        if (PHP_VERSION_ID >= 80000) {
            foreach ($keys as $key) {
                if (array_key_exists($key, $values)) {
                    $items[$key] = new Typed_Cache_Item($key, $values[$key], true);
                } else {
                    $items[$key] = new Typed_Cache_Item($key, null, false);
                }
            }
            return $items;
        }
        foreach ($keys as $key) {
            if (array_key_exists($key, $values)) {
                $items[$key] = new Cache_Item($key, $values[$key], true);
            } else {
                $items[$key] = new Cache_Item($key, null, false);
            }
        }
        return $items;
    }
    /**
     * {@inheritDoc}
     */
    public function has_item($key): bool
    {
        assert(self::valid_key($key));
        if (isset($this->deferred_items[$key])) {
            $this->commit();
        }
        return $this->cache->contains($key);
    }
    public function clear(): bool
    {
        $this->deferred_items = [];
        if (!$this->cache instanceof Clearable_Cache) {
            return false;
        }
        return $this->cache->delete_all();
    }
    /**
     * {@inheritDoc}
     */
    public function delete_item($key): bool
    {
        assert(self::valid_key($key));
        unset($this->deferred_items[$key]);
        return $this->cache->delete($key);
    }
    /**
     * {@inheritDoc}
     */
    public function delete_items(array $keys): bool
    {
        foreach ($keys as $key) {
            assert(self::valid_key($key));
            unset($this->deferred_items[$key]);
        }
        return $this->do_delete_multiple($keys);
    }
    public function save(Cache_Item_Interface $item): bool
    {
        return $this->save_deferred($item) && $this->commit();
    }
    public function save_deferred(Cache_Item_Interface $item): bool
    {
        if (!$item instanceof Cache_Item && !$item instanceof Typed_Cache_Item) {
            return false;
        }
        $this->deferred_items[$item->get_key()] = $item;
        return true;
    }
    public function commit(): bool
    {
        if (!$this->deferred_items) {
            return true;
        }
        $now = microtime(true);
        $items_count = 0;
        $by_lifetime = [];
        $expired_keys = [];
        foreach ($this->deferred_items as $key => $item) {
            $lifetime = ($item->get_expiry() ?? $now) - $now;
            if ($lifetime < 0) {
                $expired_keys[] = $key;
                continue;
            }
            ++$items_count;
            $by_lifetime[(int) $lifetime][$key] = $item->get();
        }
        $this->deferred_items = [];
        switch (count($expired_keys)) {
            case 0:
                break;
            case 1:
                $this->cache->delete(current($expired_keys));
                break;
            default:
                $this->do_delete_multiple($expired_keys);
                break;
        }
        if ($items_count === 1) {
            return $this->cache->save($key, $item->get(), (int) $lifetime);
        }
        $success = true;
        foreach ($by_lifetime as $lifetime => $values) {
            $success = $this->do_save_multiple($values, $lifetime) && $success;
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
    private static function valid_key($key): bool
    {
        if (!is_string($key)) {
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
    private static function valid_keys(array $keys): bool
    {
        foreach ($keys as $key) {
            self::valid_key($key);
        }
        return true;
    }
    /**
     * @param mixed[] $keys
     */
    private function do_delete_multiple(array $keys): bool
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
    private function do_fetch_multiple(array $keys): array
    {
        if ($this->cache instanceof Multi_Get_Cache) {
            return $this->cache->fetch_multiple($keys);
        }
        $values = [];
        foreach ($keys as $key) {
            $value = $this->cache->fetch($key);
            if (!$value) {
                continue;
            }
            $values[$key] = $value;
        }
        return $values;
    }
    /**
     * @param mixed[] $keysAndValues
     */
    private function do_save_multiple(array $keys_and_values, int $lifetime = 0): bool
    {
        if ($this->cache instanceof Multi_Put_Cache) {
            return $this->cache->save_multiple($keys_and_values, $lifetime);
        }
        $success = true;
        foreach ($keys_and_values as $key => $value) {
            $success = $this->cache->save($key, $value, $lifetime) && $success;
        }
        return $success;
    }
}