<?php

declare(strict_types=1);

namespace Doctrine\Common\Cache\Psr6;

use Countable;
use IteratorAggregate;
use Psr\Cache\CacheItemInterface;
use Traversable;

/**
 * Preserves string cache keys (including numeric strings) when iterating PSR-6 items.
 *
 * @implements IteratorAggregate<string, CacheItemInterface>
 */
final class String_Keyed_Item_Iterable implements Countable, IteratorAggregate
{
    /** @var list<array{0: string, 1: CacheItemInterface}> */
    private array $pairs;

    /**
     * @param list<array{0: string, 1: CacheItemInterface}> $pairs
     */
    public function __construct(array $pairs)
    {
        $this->pairs = $pairs;
    }

    public function getIterator(): Traversable
    {
        foreach ($this->pairs as [$key, $item]) {
            yield $key => $item;
        }
    }

    public function count(): int
    {
        return count($this->pairs);
    }
}
