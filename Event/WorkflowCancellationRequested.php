<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Cancellation requested on an execution (e.g. parent in {@see \Gplanchat\Durable\ParentClosePolicy::RequestCancel}).
 */
final readonly class WorkflowCancellationRequested implements Event
{
    public function __construct(
        private string $executionId,
        private string $reason,
        private ?string $sourceParentExecutionId = null,
    ) {}

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function sourceParentExecutionId(): ?string
    {
        return $this->sourceParentExecutionId;
    }

    public function payload(): array
    {
        return [
            'reason' => $this->reason,
            'sourceParentExecutionId' => $this->sourceParentExecutionId,
        ];
    }
}
