<?php

declare (strict_types=1);
namespace Doctrine\Common\Cache;

/**
 * Interface for cache drivers that allows to get many items at once.
 *
 * @deprecated
 *
 * @link   www.doctrine-project.org
 */
interface Multi_Get_Cache
{
    /**
     * Returns an associative array of values for keys is found in cache.
     *
     * @param string[] $keys Array of keys to retrieve from cache
     *
     * @return mixed[] Array of retrieved values, indexed by the specified keys.
     *                 Values that couldn't be retrieved are not contained in this array.
     */
    public function fetch_multiple(array $keys);
}