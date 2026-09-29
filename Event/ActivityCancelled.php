<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

/**
 * A scheduled activity has been taken off the queue without executing (e.g. loser of a race / any).
 */
final readonly class ActivityCancelled implements Event
{
    public function __construct(
        private ExecutionId $executionId,
        private string $activityId,
        private string $reason,
    ) {}

    public function executionId(): ExecutionId
    {
        return $this->executionId;
    }

    public function activityId(): string
    {
        return $this->activityId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function payload(): array
    {
        return [
            'activityId' => $this->activityId,
            'reason' => $this->reason,
        ];
    }
}
