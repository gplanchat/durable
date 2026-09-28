<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\Event;

/**
 * The journal as one pass sees it (DUR053): every append goes through the fence the pass claimed,
 * so a pass that a newer one has superseded can no longer write, whatever its workflow code catches.
 *
 * Handed to a pass's writers only. A fact from outside the pass (an activity outcome, a signal)
 * goes on the store itself, unfenced.
 */
final class PassEventStore implements EventStoreInterface
{
    private function __construct(
        private readonly FencedEventStoreInterface $store,
        private readonly PassFence $fence,
    ) {}

    /**
     * Claims a pass on `$executionId` when the store can fence; otherwise hands the store back as
     * it is, which appends as it always did.
     */
    public static function open(EventStoreInterface $store, string $executionId): EventStoreInterface
    {
        return $store instanceof FencedEventStoreInterface
            ? new self($store, $store->claimPass($executionId))
            : $store;
    }

    public function append(Event $event): void
    {
        $this->store->appendFenced($event, $this->fence);
    }

    public function readStream(string $executionId): iterable
    {
        return $this->store->readStream($executionId);
    }

    public function readStreamWithRecordedAt(string $executionId): iterable
    {
        return $this->store->readStreamWithRecordedAt($executionId);
    }

    public function countEventsInStream(string $executionId): int
    {
        return $this->store->countEventsInStream($executionId);
    }
}
