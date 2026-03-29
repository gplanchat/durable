<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

final readonly class ActivityMessage
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        public string $executionId,
        public string $activityId,
        public string $activityName,
        public array $payload,
        public array $metadata = [],
    ) {
    }

    public function attempt(): int
    {
        return $this->metadata['attempt'] ?? 1;
    }

    public function withAttempt(int $attempt): self
    {
        $metadata = $this->metadata;
        $metadata['attempt'] = $attempt;

        return new self($this->executionId, $this->activityId, $this->activityName, $this->payload, $metadata);
    }
}
