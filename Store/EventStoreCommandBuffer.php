<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;

/**
 * Implements WorkflowCommandBufferInterface by appending domain events to EventStoreInterface
 * and enqueuing activity messages via ActivityTransportInterface.
 *
 * Used by the in-memory backend. The Temporal backend uses TemporalWorkflowCommandBuffer instead.
 */
final class EventStoreCommandBuffer implements WorkflowCommandBufferInterface
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityTransportInterface $activityTransport,
        private readonly string $executionId,
    ) {
    }

    public function scheduleActivity(string $activityId, string $activityName, array $payload, array $metadata): void
    {
        $this->eventStore->append(new ActivityScheduled(
            $this->executionId,
            $activityId,
            $activityName,
            $payload,
            $metadata,
        ));
        $this->activityTransport->enqueue(new ActivityMessage(
            $this->executionId,
            $activityId,
            $activityName,
            $payload,
            $metadata,
        ));
    }

    public function startTimer(string $timerId, float $scheduledAt, string $summary): void
    {
        $this->eventStore->append(new TimerScheduled(
            $this->executionId,
            $timerId,
            $scheduledAt,
            $summary,
        ));
    }

    public function recordSideEffect(string $sideEffectId, mixed $result): void
    {
        $this->eventStore->append(new SideEffectRecorded(
            $this->executionId,
            $sideEffectId,
            $result,
        ));
    }

    public function scheduleChildWorkflow(
        string $childExecutionId,
        string $childWorkflowType,
        array $input,
        array $schedulingMetadata,
    ): void {
        $parentClosePolicy = $schedulingMetadata['parentClosePolicy'] ?? null;
        $workflowId = $schedulingMetadata['workflowId'] ?? null;
        $this->eventStore->append(new ChildWorkflowScheduled(
            $this->executionId,
            $childExecutionId,
            $childWorkflowType,
            $input,
            $parentClosePolicy,
            $workflowId,
            $schedulingMetadata,
        ));
    }

    public function completeWorkflow(mixed $result): void
    {
        $this->eventStore->append(new ExecutionCompleted(
            $this->executionId,
            $result,
        ));
    }

    public function failWorkflow(\Throwable $reason): void
    {
        $this->eventStore->append(WorkflowExecutionFailed::workflowHandlerFailure(
            $this->executionId,
            $reason,
        ));
    }

    public function cancelActivity(string $activityId, string $reason): void
    {
        $this->activityTransport->removePendingFor($this->executionId, $activityId);
        $this->eventStore->append(new ActivityCancelled(
            $this->executionId,
            $activityId,
            $reason,
        ));
    }
}
