<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\Event;

final class InMemoryEventStore implements EventStoreInterface
{
    /** @var array<string, list<Event>> */
    private array $streams = [];

    public function append(Event $event): void
    {
        $id = $event->executionId();
        if (!isset($this->streams[$id])) {
            $this->streams[$id] = [];
        }
        $this->streams[$id][] = $event;
    }

    public function readStream(string $executionId): iterable
    {
        yield from $this->streams[$executionId] ?? [];
    }
}
