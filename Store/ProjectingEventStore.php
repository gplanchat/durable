<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunStatus;

/**
 * Decorates the journal to read the outcome of executions from it.
 *
 * The four endings arrive here typed and in a single place — `EventStoreWorkflowLifecycle` appends
 * them all — where the metadata store confuses them in one and the same `delete()`. That is what
 * makes a decorator possible here and impossible over there.
 *
 * This lifecycle is the journal backend's; Temporal uses `TemporalWorkflowLifecycle`, so none of
 * this fires on a Temporal application.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/design.md
 */
final class ProjectingEventStore implements EventStoreInterface
{
    public function __construct(
        private readonly EventStoreInterface $inner,
        private readonly WorkflowRunProjectionInterface $projection,
    ) {}

    public function append(Event $event): void
    {
        $this->inner->append($event);

        $status = self::outcomeOf($event);
        if (null !== $status) {
            $this->projection->recordOutcome($event->executionId(), $status);
        }
    }

    public function readStream(string $executionId): iterable
    {
        return $this->inner->readStream($executionId);
    }

    public function readStreamWithRecordedAt(string $executionId): iterable
    {
        return $this->inner->readStreamWithRecordedAt($executionId);
    }

    public function countEventsInStream(string $executionId): int
    {
        return $this->inner->countEventsInStream($executionId);
    }

    /**
     * `null` for anything that is not an ending: the projection only moves on the four transitions
     * that terminate an execution.
     */
    private static function outcomeOf(Event $event): ?WorkflowRunStatus
    {
        return match (true) {
            $event instanceof ExecutionCompleted => WorkflowRunStatus::Completed,
            $event instanceof WorkflowExecutionFailed => WorkflowRunStatus::Failed,
            $event instanceof WorkflowExecutionCancelled => WorkflowRunStatus::Cancelled,
            $event instanceof WorkflowContinuedAsNew => WorkflowRunStatus::ContinuedAsNew,
            default => null,
        };
    }
}
