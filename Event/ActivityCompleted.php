<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

final readonly class ActivityCompleted implements Event
{
    public function __construct(
        private ExecutionId $executionId,
        private string $activityId,
        private mixed $result,
    ) {}

    public function executionId(): string
    {
        return $this->executionId->toString();
    }

    public function activityId(): string
    {
        return $this->activityId;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function payload(): array
    {
        return [
            'activityId' => $this->activityId,
            'result' => $this->result,
        ];
    }
}
