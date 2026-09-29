<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

/**
 * Worker-side marker: raw activity invocation finished successfully (before {@see ActivityCompleted} in workflow history).
 */
final readonly class ActivityTaskCompleted implements Event
{
    public function __construct(
        private ExecutionId|string $executionId,
        private string $activityId,
        private mixed $result,
    ) {}

    public function executionId(): string
    {
        return (string) $this->executionId;
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
