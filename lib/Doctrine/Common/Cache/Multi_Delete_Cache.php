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
interface Multi_Delete_Cache
{
    /**
     * Deletes several cache entries.
     *
     * @param string[] $keys Array of keys to delete from cache
     *
     * @return bool TRUE if the operation was successful, FALSE if it wasn't.
     */
    public function delete_multiple(array $keys);
}