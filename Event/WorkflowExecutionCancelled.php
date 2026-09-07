<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

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
