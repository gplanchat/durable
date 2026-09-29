<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Exception\SupersededPassException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\SystemClock;
use Psr\Clock\ClockInterface;

final class InMemoryEventStore implements FencedEventStoreInterface
{
    private readonly ClockInterface $clock;

    /** @var array<string, int> the newest epoch claimed per execution (DUR053) */
    private array $epochs = [];

    /** @var array<string, list<array{event: Event, recordedAt: \DateTimeImmutable}>> */
    private array $streams = [];

    public function __construct(?ClockInterface $clock = null)
    {
        $this->clock = $clock ?? new SystemClock();
    }

    public function append(Event $event): void
    {
        $id = $event->executionId();
        if (!isset($this->streams[$id])) {
            $this->streams[$id] = [];
        }
        $this->streams[$id][] = [
            'event' => $event,
            'recordedAt' => $this->clock->now()->setTimezone(new \DateTimeZone('UTC')),
        ];
    }

    public function claimPass(ExecutionId|string $executionId): PassFence
    {
        $executionId = (string) $executionId;
        $this->epochs[$executionId] = ($this->epochs[$executionId] ?? 0) + 1;

        return new PassFence($executionId, $this->epochs[$executionId]);
    }

    public function appendFenced(Event $event, PassFence $fence): void
    {
        if ($fence->fences() && $fence->epoch !== ($this->epochs[$fence->executionId] ?? 0)) {
            throw SupersededPassException::for($fence);
        }

        $this->append($event);
    }

    public function readStream(ExecutionId $executionId): iterable
    {
        foreach ($this->readStreamWithRecordedAt($executionId) as $entry) {
            yield $entry['event'];
        }
    }

    public function readStreamWithRecordedAt(ExecutionId $executionId): iterable
    {
        foreach ($this->streams[$executionId->toString()] ?? [] as $entry) {
            yield $entry;
        }
    }

    public function countEventsInStream(ExecutionId $executionId): int
    {
        return \count($this->streams[$executionId->toString()] ?? []);
    }
}
