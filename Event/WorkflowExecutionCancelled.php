<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

/**
 * End of execution on cancellation — the terminal counterpart of
 * {@see WorkflowCancellationRequested}, which had none: a child in
 * {@see \Gplanchat\Durable\ParentClosePolicy::RequestCancel} stayed "active" forever in the eyes of
 * {@see \Gplanchat\Durable\ParentChildWorkflowCoordinator::isChildRunActive()}.
 *
 * ponytail: cooperative cancellation, honoured at the next resumption point. No exception is
 * injected into the running fiber; a true Temporal-style `CancelledFailure` would call for
 * resuming the fiber through the exception, not for replaying it.
 */
final readonly class WorkflowExecutionCancelled implements Event
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
