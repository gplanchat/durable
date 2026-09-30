<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

/**
 * Successful end of a child workflow (journal of the **parent**).
 */
final readonly class ChildWorkflowCompleted implements Event
{
    public function __construct(
        private ExecutionId $parentExecutionId,
        private ExecutionId|string $childExecutionId,
        private mixed $result,
    ) {}

    public function executionId(): ExecutionId
    {
        return $this->parentExecutionId;
    }

    public function childExecutionId(): string
    {
        return (string) $this->childExecutionId;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function payload(): array
    {
        return [
            'childExecutionId' => (string) $this->childExecutionId,
            'result' => $this->result,
        ];
    }
}
