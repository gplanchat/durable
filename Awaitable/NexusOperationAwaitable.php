<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Wrapper for an awaitable produced by scheduling a Nexus operation, so that it can be cancelled
 * — the loser of an {@see any()} / {@see race()} as much as a cancelled workflow.
 *
 * Same role as {@see ActivityAwaitable}, and for the same reason: with no identity carried,
 * {@see AwaitableCancellation} has nothing to address and the operation keeps running at the
 * provider while nobody is waiting for its result any more. A Nexus operation is served by
 * another system, often another team: leaving it running there costs more than an activity
 * forgotten at home.
 *
 * The identity carried is the domain one. The Temporal bridge translates it into the real
 * `scheduledEventId`, read from the history, when emitting `RequestCancelNexusOperation` — a
 * counter invented locally has already silenced that command once, for activities.
 *
 * @implements Awaitable<mixed>
 */
final class NexusOperationAwaitable implements Awaitable
{
    /**
     * @param Awaitable<mixed> $inner
     */
    public function __construct(
        private readonly Awaitable $inner,
        private readonly string $operationId,
    ) {}

    public function operationId(): string
    {
        return $this->operationId;
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
