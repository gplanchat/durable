<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\Event;

/**
 * No-op event store stub — used when an EventStoreInterface is required by signature
 * but the call site operates in distributed mode (WorkflowTaskRunner / Temporal backend)
 * where EventStoreInterface methods are never actually called.
 */
final class NullEventStore implements EventStoreInterface
{
    public function append(Event $event): void
    {
    }

    public function readStream(string $executionId): iterable
    {
        return [];
    }

    public function readStreamWithRecordedAt(string $executionId): iterable
    {
        return [];
    }

    public function countEventsInStream(string $executionId): int
    {
        return 0;
    }
}
