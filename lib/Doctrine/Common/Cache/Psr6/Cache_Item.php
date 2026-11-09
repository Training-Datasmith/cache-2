<?php

declare(strict_types=1);

namespace Doctrine\Common\Cache\Psr6;

use DateInterval;
use DateTime;
use DateTimeInterface;
use Psr\Cache\CacheItemInterface;
use TypeError;

use function get_class;
use function gettype;
use function is_int;
use function is_object;
use function microtime;
use function sprintf;

final class Cache_Item implements CacheItemInterface
{
    /** @var string */
    private $key;

    /** @var mixed */
    private $value;

    /** @var bool */
    private $is_hit;

    /** @var float|null */
    private $expiry;

    /**
     * @internal
     *
     * @param mixed $data
     */
    public function __construct(string $key, $data, bool $is_hit)
    {
        $this->key    = $key;
        $this->value  = $data;
        $this->is_hit = $is_hit;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function get(): mixed
    {
        return $this->value;
    }

    public function isHit(): bool
    {
        return $this->is_hit;
    }

    public function set(mixed $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function expiresAt(?DateTimeInterface $expiration): static
    {
        if ($expiration === null) {
            $this->expiry = null;
        } else {
            $this->expiry = (float) $expiration->format('U.u');
        }

        return $this;
    }

    public function expiresAfter(int|DateInterval|null $time): static
    {
        if ($time === null) {
            $this->expiry = null;
        } elseif ($time instanceof DateInterval) {
            $this->expiry = microtime(true) + DateTime::createFromFormat('U', '0')->add($time)->format('U.u');
        } elseif (is_int($time)) {
            $this->expiry = $time + microtime(true);
        } else {
            throw new TypeError(sprintf(
                'Expected $time to be either an integer, an instance of DateInterval or null, got %s',
                is_object($time) ? get_class($time) : gettype($time)
            ));
        }

        return $this;
    }

    /**
     * @internal
     */
    public function get_expiry(): ?float
    {
        return $this->expiry;
    }
}
