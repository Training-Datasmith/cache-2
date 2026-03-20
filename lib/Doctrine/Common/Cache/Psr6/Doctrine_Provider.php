<?php

declare (strict_types=1);
/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */
namespace Doctrine\Common\Cache\Psr6;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\Cache_Provider;
use Psr\Cache\Cache_Item_Pool_Interface;
use function rawurlencode;
use Symfony\Component\Cache\Adapter\Doctrine_Adapter as SymfonyDoctrineAdapter;
use Symfony\Contracts\Service\Reset_Interface;
/**
 * This class was copied from the Symfony Framework, see the original copyright
 * notice above. The code is distributed subject to the license terms in
 * https://github.com/symfony/symfony/blob/ff0cf61278982539c49e467db9ab13cbd342f76d/LICENSE
 */
final class Doctrine_Provider extends Cache_Provider
{
    /** @var CacheItemPoolInterface */
    private $pool;
    public static function wrap(Cache_Item_Pool_Interface $pool): Cache
    {
        if ($pool instanceof Cache_Adapter) {
            return $pool->get_cache();
        }
        if ($pool instanceof Symfony_Doctrine_Adapter) {
            $get_cache = function () {
                // phpcs:ignore Squiz.Scope.StaticThisUsage.Found
                return $this->provider;
            };
            return $get_cache->bind_to($pool, Symfony_Doctrine_Adapter::class)();
        }
        return new self($pool);
    }
    private function __construct(Cache_Item_Pool_Interface $pool)
    {
        $this->pool = $pool;
    }
    /** @internal */
    public function get_pool(): Cache_Item_Pool_Interface
    {
        return $this->pool;
    }
    public function reset(): void
    {
        if ($this->pool instanceof Reset_Interface) {
            $this->pool->reset();
        }
        $this->set_namespace($this->get_namespace());
    }
    /**
     * {@inheritdoc}
     */
    protected function do_fetch($id)
    {
        $item = $this->pool->get_item(rawurlencode($id));
        return $item->is_hit() ? $item->get() : false;
    }
    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    protected function do_contains($id)
    {
        return $this->pool->has_item(rawurlencode($id));
    }
    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    protected function do_save($id, $data, $life_time = 0)
    {
        $item = $this->pool->get_item(rawurlencode($id));
        if (0 < $life_time) {
            $item->expires_after($life_time);
        }
        return $this->pool->save($item->set($data));
    }
    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    protected function do_delete($id)
    {
        return $this->pool->delete_item(rawurlencode($id));
    }
    /**
     * {@inheritdoc}
     *
     * @return bool
     */
    protected function do_flush()
    {
        return $this->pool->clear();
    }
    /**
     * {@inheritdoc}
     *
     * @return array|null
     */
    protected function do_get_stats()
    {
        return null;
    }
}