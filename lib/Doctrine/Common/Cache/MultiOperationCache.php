<?php

declare (strict_types=1);
namespace Doctrine\Common\Cache;

/**
 * Interface for cache drivers that supports multiple items manipulation.
 *
 * @link   www.doctrine-project.org
 */
interface Multi_Operation_Cache extends Multi_Get_Cache, Multi_Delete_Cache, Multi_Put_Cache
{
}