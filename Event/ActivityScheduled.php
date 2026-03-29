<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

final readonly class ActivityScheduled implements Event
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        private string $executionId,
        private string $activityId,
        private string $activityName,
        private array $payload,
        private array $metadata = [],
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

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'activityId' => $this->activityId,
            'activityName' => $this->activityName,
            'payload' => $this->payload,
            'metadata' => $this->metadata,
        ];
    }
}
