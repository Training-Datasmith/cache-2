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

    private int $hits_count = 0;

    private int $misses_count = 0;

    private int $up_time;

    public function __construct()
    {
        $this->up_time = time();
    }

    protected function do_fetch($id)
    {
        if (! $this->do_contains($id)) {
            ++$this->misses_count;

            return false;
        }

        ++$this->hits_count;

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

    protected function do_save($id, $data, $life_time = 0)
    {
        $this->data[$id] = [$data, $life_time ? time() + $life_time : false];

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
            Cache::STATS_HITS             => $this->hits_count,
            Cache::STATS_MISSES           => $this->misses_count,
            Cache::STATS_UPTIME           => $this->up_time,
            Cache::STATS_MEMORY_USAGE     => null,
            Cache::STATS_MEMORY_AVAILABLE => null,
        ];
    }
}
