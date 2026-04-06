<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Worker-side marker: activity execution attempt has started (idempotency vs duplicate deliveries).
 */
final readonly class ActivityTaskStarted implements Event
{
    public function __construct(
        private string $executionId,
        private string $activityId,
        private string $activityName,
        private int $attempt,
    ) {
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function activityId(): string
    {
        return $this->activityId;
    }

    public function activityName(): string
    {
        return $this->activityName;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function payload(): array
    {
        return [
            'activityId' => $this->activityId,
            'activityName' => $this->activityName,
            'attempt' => $this->attempt,
        ];
    }
}
