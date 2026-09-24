<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Observation\WaitReason;
use Gplanchat\Durable\Observation\WorkflowRunPickupProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Observation\WorkflowRunWaitProjectionInterface;

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

        // A worker appends ExecutionStarted when it picks the run up. Except for a continued run: the
        // worker that ended its predecessor writes its start, and its pickup is recorded when a worker
        // takes its message (#322, #447).
        if ($event instanceof ExecutionStarted
            && !isset($event->payload()['continuedFromExecutionId'])
            && $this->projection instanceof WorkflowRunPickupProjectionInterface) {
            $this->projection->recordPickup($event->executionId());
        }

        // The run waits on this activity while a worker runs it: the attempt number is only known here.
        if ($event instanceof ActivityTaskStarted && $this->projection instanceof WorkflowRunWaitProjectionInterface) {
            $this->projection->recordWait($event->executionId(), WaitReason::attempt($event));
        }

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
