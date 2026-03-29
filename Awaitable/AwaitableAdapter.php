<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * @implements Awaitable<mixed>
 */
final class AwaitableAdapter implements Awaitable
{
    public function __construct(
        private readonly Deferred $deferred,
    ) {
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
    {
        $this->deferred->then($onFulfilled, $onRejected);
    }

    public function isSettled(): bool
    {
        return $this->deferred->isSettled();
    }

    public function getResult(): mixed
    {
        if (!$this->deferred->isSettled()) {
            throw new \RuntimeException('Awaitable is not settled');
        }
        if (!$this->deferred->isFulfilled()) {
            throw $this->deferred->reason();
        }

        return $this->deferred->value();
    }
}
