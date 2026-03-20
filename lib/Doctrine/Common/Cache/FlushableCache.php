<?php

declare (strict_types=1);
namespace Doctrine\Common\Cache;

/**
 * Interface for cache that can be flushed.
 *
 * @link   www.doctrine-project.org
 */
interface Flushable_Cache
{
    /**
     * Flushes all cache entries, globally.
     *
     * @return bool TRUE if the cache entries were successfully flushed, FALSE otherwise.
     */
    public function flush_all();
}