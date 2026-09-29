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
        private string $childExecutionId,
        private mixed $result,
    ) {}

    public function executionId(): string
    {
        return $this->parentExecutionId->toString();
    }

    public function childExecutionId(): string
    {
        return $this->childExecutionId;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function payload(): array
    {
        return [
            'childExecutionId' => $this->childExecutionId,
            'result' => $this->result,
        ];
    }
}
