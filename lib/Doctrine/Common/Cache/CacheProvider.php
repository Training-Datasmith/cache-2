<?php

declare (strict_types=1);
namespace Doctrine\Common\Cache;

use function array_combine;
use function array_key_exists;
use function array_map;
use function sprintf;
/**
 * Base class for cache provider implementations.
 */
abstract class Cache_Provider implements Cache, Flushable_Cache, Clearable_Cache, Multi_Operation_Cache
{
    public const DOCTRINE_NAMESPACE_CACHEKEY = 'DoctrineNamespaceCacheKey[%s]';
    /**
     * The namespace to prefix all cache ids with.
     *
     * @var string
     */
    private $namespace = '';
    /**
     * The namespace version.
     *
     * @var int|null
     */
    private $namespace_version;
    /**
     * Sets the namespace to prefix all cache ids with.
     *
     * @param string $namespace
     */
    public function set_namespace($namespace): void
    {
        $this->namespace = (string) $namespace;
        $this->namespace_version = null;
    }
    /**
     * Retrieves the namespace that prefixes all cache ids.
     *
     * @return string
     */
    public function get_namespace()
    {
        return $this->namespace;
    }
    /**
     * {@inheritdoc}
     */
    public function fetch($id)
    {
        return $this->do_fetch($this->get_namespaced_id($id));
    }
    /**
     * {@inheritdoc}
     */
    public function fetch_multiple(array $keys)
    {
        if (empty($keys)) {
            return [];
        }
        // note: the array_combine() is in place to keep an association between our $keys and the $namespacedKeys
        $namespaced_keys = array_combine($keys, array_map([$this, 'getNamespacedId'], $keys));
        $items = $this->do_fetch_multiple($namespaced_keys);
        $found_items = [];
        // no internal array function supports this sort of mapping: needs to be iterative
        // this filters and combines keys in one pass
        foreach ($namespaced_keys as $requested_key => $namespaced_key) {
            if (!isset($items[$namespaced_key]) && !array_key_exists($namespaced_key, $items)) {
                continue;
            }
            $found_items[$requested_key] = $items[$namespaced_key];
        }
        return $found_items;
    }
    /**
     * {@inheritdoc}
     */
    public function save_multiple(array $keys_and_values, $lifetime = 0)
    {
        $namespaced_keys_and_values = [];
        foreach ($keys_and_values as $key => $value) {
            $namespaced_keys_and_values[$this->get_namespaced_id($key)] = $value;
        }
        return $this->do_save_multiple($namespaced_keys_and_values, $lifetime);
    }
    /**
     * {@inheritdoc}
     */
    public function contains($id)
    {
        return $this->do_contains($this->get_namespaced_id($id));
    }
    /**
     * {@inheritdoc}
     */
    public function save($id, $data, $life_time = 0)
    {
        return $this->do_save($this->get_namespaced_id($id), $data, $life_time);
    }
    /**
     * {@inheritdoc}
     */
    public function delete_multiple(array $keys)
    {
        return $this->do_delete_multiple(array_map([$this, 'getNamespacedId'], $keys));
    }
    /**
     * {@inheritdoc}
     */
    public function delete($id)
    {
        return $this->do_delete($this->get_namespaced_id($id));
    }
    /**
     * {@inheritdoc}
     */
    public function get_stats()
    {
        return $this->do_get_stats();
    }
    /**
     * {@inheritDoc}
     */
    public function flush_all()
    {
        return $this->do_flush();
    }
    /**
     * {@inheritDoc}
     */
    public function delete_all()
    {
        $namespace_cache_key = $this->get_namespace_cache_key();
        $namespace_version = $this->get_namespace_version() + 1;
        if ($this->do_save($namespace_cache_key, $namespace_version)) {
            $this->namespace_version = $namespace_version;
            return true;
        }
        return false;
    }
    /**
     * Prefixes the passed id with the configured namespace value.
     *
     * @param string $id The id to namespace.
     *
     * @return string The namespaced id.
     */
    private function get_namespaced_id(string $id): string
    {
        $namespace_version = $this->get_namespace_version();
        return sprintf('%s[%s][%s]', $this->namespace, $id, $namespace_version);
    }
    /**
     * Returns the namespace cache key.
     */
    private function get_namespace_cache_key(): string
    {
        return sprintf(self::DOCTRINE_NAMESPACE_CACHEKEY, $this->namespace);
    }
    /**
     * Returns the namespace version.
     */
    private function get_namespace_version(): int
    {
        if ($this->namespace_version !== null) {
            return $this->namespace_version;
        }
        $namespace_cache_key = $this->get_namespace_cache_key();
        $this->namespace_version = (int) $this->do_fetch($namespace_cache_key) ?: 1;
        return $this->namespace_version;
    }
    /**
     * Default implementation of doFetchMultiple. Each driver that supports multi-get should owerwrite it.
     *
     * @param string[] $keys Array of keys to retrieve from cache
     *
     * @return mixed[] Array of values retrieved for the given keys.
     */
    protected function do_fetch_multiple(array $keys)
    {
        $return_values = [];
        foreach ($keys as $key) {
            $item = $this->do_fetch($key);
            if ($item === false && !$this->do_contains($key)) {
                continue;
            }
            $return_values[$key] = $item;
        }
        return $return_values;
    }
    /**
     * Fetches an entry from the cache.
     *
     * @param string $id The id of the cache entry to fetch.
     *
     * @return mixed|false The cached data or FALSE, if no cache entry exists for the given id.
     */
    abstract protected function do_fetch($id);
    /**
     * Tests if an entry exists in the cache.
     *
     * @param string $id The cache id of the entry to check for.
     *
     * @return bool TRUE if a cache entry exists for the given cache id, FALSE otherwise.
     */
    abstract protected function do_contains($id);
    /**
     * Default implementation of doSaveMultiple. Each driver that supports multi-put should override it.
     *
     * @param mixed[] $keysAndValues Array of keys and values to save in cache
     * @param int     $lifetime      The lifetime. If != 0, sets a specific lifetime for these
     *                               cache entries (0 => infinite lifeTime).
     *
     * @return bool TRUE if the operation was successful, FALSE if it wasn't.
     */
    protected function do_save_multiple(array $keys_and_values, $lifetime = 0)
    {
        $success = true;
        foreach ($keys_and_values as $key => $value) {
            if ($this->do_save($key, $value, $lifetime)) {
                continue;
            }
            $success = false;
        }
        return $success;
    }
    /**
     * Puts data into the cache.
     *
     * @param string $id       The cache id.
     * @param string $data     The cache entry/data.
     * @param int    $lifeTime The lifetime. If != 0, sets a specific lifetime for this
     *                           cache entry (0 => infinite lifeTime).
     *
     * @return bool TRUE if the entry was successfully stored in the cache, FALSE otherwise.
     */
    abstract protected function do_save($id, $data, $life_time = 0);
    /**
     * Default implementation of doDeleteMultiple. Each driver that supports multi-delete should override it.
     *
     * @param string[] $keys Array of keys to delete from cache
     *
     * @return bool TRUE if the operation was successful, FALSE if it wasn't
     */
    protected function do_delete_multiple(array $keys)
    {
        $success = true;
        foreach ($keys as $key) {
            if ($this->do_delete($key)) {
                continue;
            }
            $success = false;
        }
        return $success;
    }
    /**
     * Deletes a cache entry.
     *
     * @param string $id The cache id.
     *
     * @return bool TRUE if the cache entry was successfully deleted, FALSE otherwise.
     */
    abstract protected function do_delete($id);
    /**
     * Flushes all cache entries.
     *
     * @return bool TRUE if the cache entries were successfully flushed, FALSE otherwise.
     */
    abstract protected function do_flush();
    /**
     * Retrieves cached information from the data store.
     *
     * @return mixed[]|null An associative array with server's statistics if available, NULL otherwise.
     */
    abstract protected function do_get_stats();
}