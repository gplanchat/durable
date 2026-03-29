<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Une activité planifiée a été retirée de la file sans exécution (ex. perdante d'un race / any).
 */
final readonly class ActivityCancelled implements Event
{
    public function __construct(
        private string $executionId,
        private string $activityId,
        private string $reason,
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
