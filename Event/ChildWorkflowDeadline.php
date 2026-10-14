<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

/**
 * The moment a child workflow must have ended by (journal of the **parent**): the scheduling time
 * plus the child's execution timeout. The journal backends have no server to enforce a timeout.
 * The engine reads this event for a child it runs inline, and fails the parent's await with
 * "timed out", as Temporal does. A child that runs on a worker is not bound by it yet (#1069).
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
