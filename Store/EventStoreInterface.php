<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\Event;

/**
 * Persistence port for workflow events (event sourcing).
 *
 * @see DUR002 (CQRS repositories, ports around the event journal)
 */
interface EventStoreInterface
{
    public function append(Event $event): void;

    /**
     * @return iterable<Event> events of the identified execution only, in insertion order; the DBAL store walks the result as a cursor
     */
    public function readStream(string $executionId): iterable;

    /**
     * The same stream as {@see readStream} with the store-side recording instant (Temporal-style "Event time" profile).
     *
     * @return iterable<array{event: Event, recordedAt: \DateTimeImmutable|null}>
     */
    public function readStreamWithRecordedAt(string $executionId): iterable;

    /**
     * Number of events persisted for this execution (equivalent to counting the {@see readStream} stream without materialising it entirely on the DBAL side where possible).
     */
    public function countEventsInStream(string $executionId): int;
}
