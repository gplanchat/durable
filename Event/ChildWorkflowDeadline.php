<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

/**
 * The moment a child workflow must have ended by (journal of the **parent**): the scheduling time
 * plus the child's execution timeout. The journal backends have no server to enforce a timeout,
 * so this event is what a wake-up reads. A child that has no outcome in the parent's journal when
 * the deadline falls is failed with "timed out", as Temporal does.
 */
final readonly class ChildWorkflowDeadline implements Event
{
    public function __construct(
        private ExecutionId $parentExecutionId,
        private ExecutionId $childExecutionId,
        private float $dueAt,
    ) {}

    public function executionId(): ExecutionId
    {
        return $this->parentExecutionId;
    }

    public function childExecutionId(): ExecutionId
    {
        return $this->childExecutionId;
    }

    /**
     * Unix time, in seconds.
     */
    public function dueAt(): float
    {
        return $this->dueAt;
    }

    public function payload(): array
    {
        return [
            'childExecutionId' => $this->childExecutionId->toString(),
            'dueAt' => $this->dueAt,
        ];
    }
}
