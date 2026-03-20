<?php

declare (strict_types=1);
namespace Doctrine\Common\Cache;

/**
 * Interface for cache drivers that allows to put many items at once.
 *
 * @deprecated
 *
 * @link   www.doctrine-project.org
 */
interface Multi_Put_Cache
{
    /**
     * Returns a boolean value indicating if the operation succeeded.
     *
     * @param mixed[] $keysAndValues Array of keys and values to save in cache
     * @param int     $lifetime      The lifetime. If != 0, sets a specific lifetime for these
     *                               cache entries (0 => infinite lifeTime).
     *
     * @return bool TRUE if the operation was successful, FALSE if it wasn't.
     */
    public function save_multiple(array $keys_and_values, $lifetime = 0);
}