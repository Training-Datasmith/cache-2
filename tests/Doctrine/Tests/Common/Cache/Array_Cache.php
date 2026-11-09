<?php

declare(strict_types=1);

namespace Doctrine\Tests\Common\Cache;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\Cache_Provider;

use function time;

/**
 * Array cache driver used for testing
 *
 * @internal
 */
class Array_Cache extends Cache_Provider
{
    /** @psalm-var array<string, array{mixed, int|bool}> */
    private array $data = [];

    private int $hitsCount = 0;

    private int $missesCount = 0;

    private int $upTime;

    public function __construct()
    {
        $this->upTime = time();
    }

    protected function do_fetch($id)
    {
        if (! $this->do_contains($id)) {
            ++$this->missesCount;

            return false;
        }

        ++$this->hitsCount;

        return $this->data[$id][0];
    }

    protected function do_contains($id)
    {
        if (! isset($this->data[$id])) {
            return false;
        }

        $expiration = $this->data[$id][1];

        if ($expiration && $expiration < time()) {
            $this->do_delete($id);

            return false;
        }

        return true;
    }

    protected function do_save($id, $data, $lifeTime = 0)
    {
        $this->data[$id] = [$data, $lifeTime ? time() + $lifeTime : false];

        return true;
    }

    protected function do_delete($id)
    {
        unset($this->data[$id]);

        return true;
    }

    protected function do_flush()
    {
        $this->data = [];

        return true;
    }

    protected function do_get_stats()
    {
        return [
            Cache::STATS_HITS             => $this->hitsCount,
            Cache::STATS_MISSES           => $this->missesCount,
            Cache::STATS_UPTIME           => $this->upTime,
            Cache::STATS_MEMORY_USAGE     => null,
            Cache::STATS_MEMORY_AVAILABLE => null,
        ];
    }
}
