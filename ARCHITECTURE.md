# Architecture: cache-2

## Purpose

Doctrine Common Cache — a generic caching library with adapters for various backends (array, APC, Memcached, Redis, etc.) and PSR-6 compatibility bridges. It predates PSR-6 but includes adapters to expose Doctrine cache as PSR-6 and vice-versa.

## Directory Structure

```
lib/Doctrine/Common/Cache/
  Cache.php                      — Core interface: fetch, save, delete, contains, getStats
  Cache_Provider.php             — Abstract base: implements cache namespace logic on top of Cache
  Clearable_Cache.php            — Optional interface: delete all items
  Flushable_Cache.php            — Optional interface: flush the entire backend
  Multi_Get_Cache.php            — Optional interface: batch fetch
  Multi_Put_Cache.php            — Optional interface: batch save
  Multi_Delete_Cache.php         — Optional interface: batch delete
  Multi_Operation_Cache.php      — Combines Multi_Get + Multi_Put + Multi_Delete
  Psr6/
    Cache_Adapter.php            — Wraps a PSR-6 pool as a Doctrine Cache
    Doctrine_Provider.php        — Wraps a Doctrine Cache as a PSR-6 pool
    Cache_Item.php               — PSR-6 CacheItemInterface implementation
    Typed_Cache_Item.php         — Type-annotated item for stricter checking
    Invalid_Argument.php         — PSR-6 InvalidArgumentException implementation

tests/
  Doctrine/Tests/Common/Cache/   — Provider tests and array cache test implementation
```

## Key Design Decisions

- **Namespace isolation** — `Cache_Provider` prefixes all keys with a configurable namespace string, preventing key collisions when multiple applications share a backend.
- **Bidirectional PSR-6 bridge** — `Cache_Adapter` wraps PSR-6 as Doctrine Cache; `Doctrine_Provider` wraps Doctrine Cache as PSR-6 — enabling interop in both directions.
- **Optional bulk interfaces** — backends that support batch operations implement the `Multi_*` interfaces for performance; callers can check `instanceof` and use batch APIs when available.
- **Stats support** — `Cache::get_stats()` returns hit/miss/memory statistics when the backend supports them, useful for monitoring.

## Extension Points

- Extend `Cache_Provider` to add support for a new caching backend.
- Use `Doctrine_Provider` to expose a custom Doctrine cache as a PSR-6 pool.

## Dependency Flow

```
Application
  ├── Cache (Doctrine interface) ← implemented by Redis/Memcache/Array/etc providers
  └── PSR-6 (via Psr6\DoctrineProvider or Psr6\CacheAdapter)
```
