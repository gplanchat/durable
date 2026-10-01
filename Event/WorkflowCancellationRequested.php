<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

/**
 * Cancellation requested on an execution (e.g. parent in {@see \Gplanchat\Durable\ParentClosePolicy::RequestCancel}).
 */
final readonly class WorkflowCancellationRequested implements Event
{
    public function __construct(
        private ExecutionId $executionId,
        private string $reason,
        private ?ExecutionId $sourceParentExecutionId = null,
    ) {}

    public function executionId(): ExecutionId
    {
        return $this->executionId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function sourceParentExecutionId(): ?ExecutionId
    {
        return $this->sourceParentExecutionId;
    }

    public function payload(): array
    {
        return [
            'reason' => $this->reason,
            'sourceParentExecutionId' => $this->sourceParentExecutionId?->toString(),
        ];
    }
}
