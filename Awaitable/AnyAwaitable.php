<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * @implements Awaitable<mixed>
 */
final class AnyAwaitable implements Awaitable
{
    /** @param list<Awaitable<mixed>> $awaitables */
    public function __construct(
        private readonly array $awaitables,
    ) {
    }

    /**
     * @return list<Awaitable<mixed>>
     */
    public function members(): array
    {
        return $this->awaitables;
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
    {
        foreach ($this->awaitables as $a) {
            $a->then($onFulfilled, $onRejected);
        }
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
