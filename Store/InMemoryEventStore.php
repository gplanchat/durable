<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\Event;

final class InMemoryEventStore implements EventStoreInterface
{
    /** @var array<string, list<array{event: Event, recordedAt: \DateTimeImmutable}>> */
    private array $streams = [];

    public function append(Event $event): void
    {
        $id = $event->executionId();
        if (!isset($this->streams[$id])) {
            $this->streams[$id] = [];
        }
        $this->streams[$id][] = [
            'event' => $event,
            'recordedAt' => new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
        ];
    }

    public function readStream(string $executionId): iterable
    {
        foreach ($this->readStreamWithRecordedAt($executionId) as $entry) {
            yield $entry['event'];
        }
    }

    public function readStreamWithRecordedAt(string $executionId): iterable
    {
        foreach ($this->streams[$executionId] ?? [] as $entry) {
            yield $entry;
        }
    }

    public function countEventsInStream(string $executionId): int
    {
        return \count($this->streams[$executionId] ?? []);
    }
}
