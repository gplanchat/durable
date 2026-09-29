<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

/**
 * A Nexus operation has succeeded.
 *
 * The scheduling `eventId` is what ties it back to its operation: without it, the profiler would
 * only have floating events, impossible to recompose into a timeline.
 */
final readonly class NexusOperationCompleted implements Event
{
    public function __construct(
        private ExecutionId|string $executionId,
        private int $scheduledEventId,
    ) {}

    public function executionId(): string
    {
        return (string) $this->executionId;
    }

    public function scheduledEventId(): int
    {
        return $this->scheduledEventId;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return ['scheduledEventId' => $this->scheduledEventId];
    }
}
