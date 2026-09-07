<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Wrapper for an awaitable produced by {@see \Gplanchat\Durable\ExecutionContext::delay()} / {@see \Gplanchat\Durable\ExecutionContext::timer()}.
 *
 * Lets the distributed runtime tell a timer wait (resumed by worker / clock) from a signal/update wait.
 *
 * @implements Awaitable<mixed>
 */
final class TimerAwaitable implements Awaitable
{
    /**
     * @param Awaitable<mixed> $inner
     */
    public function __construct(
        private readonly Awaitable $inner,
        private readonly string $timerId,
    ) {}

    public function timerId(): string
    {
        return $this->timerId;
    }

    /**
     * @return Awaitable<mixed>
     */
    public function inner(): Awaitable
    {
        return $this->inner;
    }

    public function isSettled(): bool
    {
        return $this->inner->isSettled();
    }

    public function getResult(): mixed
    {
        return $this->inner->getResult();
    }
}
