<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Enveloppe d'un awaitable issu de {@see \Gplanchat\Durable\ExecutionContext::activity()}
 * pour permettre l'annulation des perdants d'un {@see any()} / {@see race()}.
 *
 * @implements Awaitable<mixed>
 */
final class ActivityAwaitable implements Awaitable
{
    /**
     * @param Awaitable<mixed> $inner
     */
    public function __construct(
        private readonly Awaitable $inner,
        private readonly string $activityId,
    ) {
    }

    public function activityId(): string
    {
        return $this->activityId;
    }

    /**
     * @return Awaitable<mixed>
     */
    public function inner(): Awaitable
    {
        return $this->inner;
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
    {
        $this->inner->then($onFulfilled, $onRejected);
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
