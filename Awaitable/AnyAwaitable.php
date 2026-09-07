<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Settled as soon as one member is, whatever its fate — the first to arrive decides.
 *
 * It is the quorum of one, but it does not read like a {@see QuorumAwaitable}: that one counts
 * the succeeded members and returns an array, where a race returns the value of its winner.
 *
 * @implements CompositeAwaitable<mixed>
 */
final class AnyAwaitable implements CompositeAwaitable
{
    /** @param list<Awaitable<mixed>> $awaitables */
    public function __construct(
        private readonly array $awaitables,
    ) {}

    /**
     * @return list<Awaitable<mixed>>
     */
    public function members(): array
    {
        return $this->awaitables;
    }

    public function isSettled(): bool
    {
        foreach ($this->awaitables as $a) {
            if ($a->isSettled()) {
                return true;
            }
        }

        return false;
    }

    public function getResult(): mixed
    {
        foreach ($this->awaitables as $a) {
            if ($a->isSettled()) {
                return $a->getResult();
            }
        }

        throw new \RuntimeException('AnyAwaitable: no awaitable settled');
    }
}
