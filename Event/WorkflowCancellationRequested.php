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
        private ExecutionId|string|null $sourceParentExecutionId = null,
    ) {}

    public function executionId(): ExecutionId
    {
        return $this->executionId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function sourceParentExecutionId(): ?string
    {
        return null === $this->sourceParentExecutionId ? null : (string) $this->sourceParentExecutionId;
    }

    public function payload(): array
    {
        return [
            'reason' => $this->reason,
            'sourceParentExecutionId' => null === $this->sourceParentExecutionId ? null : (string) $this->sourceParentExecutionId,
        ];
    }
}
