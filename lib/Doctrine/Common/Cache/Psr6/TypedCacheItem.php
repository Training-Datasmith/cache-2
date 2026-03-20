<?php

declare (strict_types=1);
namespace Doctrine\Common\Cache\Psr6;

use DateInterval;
use DateTime;
use DateTimeInterface;
use function get_debug_type;
use function is_int;
use function microtime;
use Psr\Cache\Cache_Item_Interface;
use function sprintf;
use TypeError;
final class Typed_Cache_Item implements Cache_Item_Interface
{
    private ?float $expiry = null;
    /**
     * @internal
     */
    public function __construct(private string $key, private mixed $value, private bool $is_hit)
    {
    }
    public function get_key(): string
    {
        return $this->key;
    }
    public function get(): mixed
    {
        return $this->value;
    }
    public function is_hit(): bool
    {
        return $this->is_hit;
    }
    public function set(mixed $value): static
    {
        $this->value = $value;
        return $this;
    }
    /**
     * {@inheritDoc}
     */
    public function expires_at($expiration): static
    {
        if ($expiration === null) {
            $this->expiry = null;
        } elseif ($expiration instanceof DateTimeInterface) {
            $this->expiry = (float) $expiration->format('U.u');
        } else {
            throw new TypeError(sprintf('Expected $expiration to be an instance of DateTimeInterface or null, got %s', get_debug_type($expiration)));
        }
        return $this;
    }
    /**
     * {@inheritDoc}
     */
    public function expires_after($time): static
    {
        if ($time === null) {
            $this->expiry = null;
        } elseif ($time instanceof DateInterval) {
            $this->expiry = microtime(true) + DateTime::create_from_format('U', 0)->add($time)->format('U.u');
        } elseif (is_int($time)) {
            $this->expiry = $time + microtime(true);
        } else {
            throw new TypeError(sprintf('Expected $time to be either an integer, an instance of DateInterval or null, got %s', get_debug_type($time)));
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