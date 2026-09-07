<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\ExecutionContext;

/**
 * Once the composite is settled, takes the branches that no longer have a purpose off the queue.
 *
 * Applies to any form of race — the first to arrive in an {@see AnyAwaitable}, as much as the
 * quorum of a {@see QuorumAwaitable}: in both cases branches stay in flight while the verdict is
 * already in, and nothing will come to claim them. Best effort: if the transport does not allow
 * it, or if the activity has already been consumed, we do not insist.
 *
 * @implements Awaitable<mixed>
 */
final class CancellingCompositeAwaitable implements Awaitable
{
    /**
     * @param CompositeAwaitable<mixed> $inner
     */
    public function __construct(
        private readonly ExecutionContext $context,
        private readonly CompositeAwaitable $inner,
    ) {}

    /**
     * @return CompositeAwaitable<mixed>
     */
    public function inner(): CompositeAwaitable
    {
        return $this->inner;
    }

    public function isSettled(): bool
    {
        return $this->inner->isSettled();
    }

    public function getResult(): mixed
    {
        // The verdict must be in before cancelling anything at all: on an unsettled composite,
        // getResult() throws, and the branches still in the race are the only chance the wait
        // has of settling.
        if (!$this->inner->isSettled()) {
            return $this->inner->getResult();
        }

        try {
            return $this->inner->getResult();
        } finally {
            AwaitableCancellation::cancelUnsettled(
                $this->context,
                $this->inner,
                ActivityCancellationReason::RACE_SUPERSEDED,
            );
        }
    }
}
