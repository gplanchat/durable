<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

/**
 * Worker-side marker: the next attempt of a failed activity was handed to the transport.
 *
 * The counterpart of Temporal's dispatch task, which the server removes once the attempt is
 * delivered: without it, a redelivered failure cannot tell a retry the broker refused from one it
 * took (#590). `attempt` is the rank of the attempt that was queued.
 */
final readonly class ActivityRetryQueued implements Event
{
    public function __construct(
        private ExecutionId|string $executionId,
        private string $activityId,
        private int $attempt,
    ) {}

    public function executionId(): string
    {
        return (string) $this->executionId;
    }

    public function activityId(): string
    {
        return $this->activityId;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function payload(): array
    {
        return [
            'activityId' => $this->activityId,
            'attempt' => $this->attempt,
        ];
    }
}
