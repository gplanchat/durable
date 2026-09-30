<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\VersionMarked;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\SystemClock;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Workflow\AsyncChildWorkflowFailureProjector;
use Psr\Clock\ClockInterface;

/**
 * Implements WorkflowCommandBufferInterface by appending domain events to EventStoreInterface
 * and enqueuing activity messages via ActivityTransportInterface.
 *
 * Used by the in-memory backend. The Temporal backend uses TemporalWorkflowCommandBuffer instead.
 */
final readonly class EventStoreCommandBuffer implements WorkflowCommandBufferInterface
{
    private readonly ClockInterface $clock;

    private readonly ExecutionId $id;

    /**
     * @param ClockInterface|null $clock the backend's clock; injectable for the harnesses that
     *                                   advance a virtual time
     */
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityTransportInterface $activityTransport,
        ExecutionId|string $executionId,
        ?ClockInterface $clock = null,
        private readonly ?EventStoreHistorySource $history = null,
    ) {
        $this->clock = $clock ?? new SystemClock();
        $this->id = $executionId instanceof ExecutionId ? $executionId : ExecutionId::fromString($executionId);
    }

    public function scheduleActivity(string $activityId, string $activityName, array $payload, ?ActivityOptions $options): void
    {
        // It is here, in the adapter, that the options take their wire form — and that the
        // enqueuing is timestamped, with this backend's clock.
        $queuedAt = $this->nowSeconds();
        $metadata = ($options?->toMetadata() ?? []) + [
            'queued_at' => $queuedAt,
            'first_queued_at' => $queuedAt,
        ];

        $this->append(new ActivityScheduled(
            $this->id,
            $activityId,
            $activityName,
            $payload,
            $metadata,
        ));
        $this->activityTransport->enqueue(new ActivityMessage(
            $this->id->toString(),
            $activityId,
            $activityName,
            $payload,
            $options,
            firstQueuedAt: $queuedAt,
        ));
    }

    public function startTimer(string $timerId, Duration $delay, string $summary): void
    {
        // This backend compares deadlines against its clock: here is where the delay becomes one.
        $this->append(new TimerScheduled(
            $this->id,
            $timerId,
            $this->nowSeconds() + $delay->toSeconds(),
            $summary,
        ));
    }

    public function recordSideEffect(string $sideEffectId, mixed $result): void
    {
        $this->append(new SideEffectRecorded(
            $this->id,
            $sideEffectId,
            $result,
        ));
    }

    public function recordUpdateHandled(string $updateName, array $arguments, mixed $result, ?FailureEnvelope $failure): void
    {
        $this->append(new WorkflowUpdateHandled(
            $this->id,
            $updateName,
            $arguments,
            $result,
            $failure,
        ));
    }

    public function scheduleChildWorkflow(
        ExecutionId $childExecutionId,
        string $childWorkflowType,
        array $input,
        ChildWorkflowOptions $options,
    ): void {
        // The wire form is built here: the journal records the flat metadata the old code was
        // already giving it, including the two keys the core used to add by hand.
        $this->append(new ChildWorkflowScheduled(
            $this->id,
            $childExecutionId->toString(),
            $childWorkflowType,
            $input,
            $options->parentClosePolicy,
            $options->workflowId,
            [
                'parentClosePolicy' => $options->parentClosePolicy->value,
                'workflowId' => $options->workflowId,
            ] + $options->toSchedulingMetadata(),
        ));
    }

    public function completeWorkflow(mixed $result): void
    {
        $this->append(new ExecutionCompleted(
            $this->id,
            $result,
        ));
    }

    public function completeChildWorkflow(ExecutionId $childExecutionId, mixed $result): void
    {
        $this->append(new ChildWorkflowCompleted(
            $this->id,
            $childExecutionId->toString(),
            $result,
        ));
    }

    public function failChildWorkflow(ExecutionId $childExecutionId, \Throwable $reason): void
    {
        // Through the projector, like an async child: the kind, class and context come from the
        // child's own WorkflowExecutionFailed, so the replay reads back what the pass saw (#318).
        $this->append(AsyncChildWorkflowFailureProjector::toParentJournalEvent(
            $this->eventStore,
            $this->id->toString(),
            $childExecutionId->toString(),
            $reason,
        ));
    }

    public function recordVersion(string $changeId, int $version): void
    {
        $this->append(new VersionMarked($this->id, $changeId, $version));
    }

    public function failWorkflow(\Throwable $reason): void
    {
        $this->append(WorkflowExecutionFailed::workflowHandlerFailure(
            $this->id,
            $reason,
        ));
    }

    public function cancelTimer(string $timerId, string $reason): void
    {
        // Replay goes through cancelLosers() again on every resume: without this guard the
        // journal would accumulate one TimerCancelled per replay.
        foreach ($this->eventStore->readStream($this->id) as $event) {
            if ($event instanceof TimerCancelled && $event->timerId() === $timerId) {
                return;
            }
        }

        $this->append(new TimerCancelled($this->id, $timerId, $reason));
    }

    public function cancelActivity(string $activityId, string $reason): void
    {
        $this->activityTransport->removePendingFor($this->id, $activityId);
        // Same guard as cancelTimer(): a race loser stays unsettled on replay, and every resume
        // cancels it again (#678).
        foreach ($this->eventStore->readStream($this->id) as $event) {
            if ($event instanceof ActivityCancelled && $event->activityId() === $activityId) {
                return;
            }
        }

        $this->append(new ActivityCancelled(
            $this->id,
            $activityId,
            $reason,
        ));
    }

    public function scheduleNexusOperation(
        string $operationId,
        NexusEndpoint $endpoint,
        NexusService $service,
        NexusOperationName $operation,
        array $payload,
        NexusOperationTimeouts $timeouts,
        NexusOperationHeaders $headers,
    ): void {
        throw NexusUnsupportedByBackendException::forBackend('journal');
    }

    public function cancelNexusOperation(string $operationId, string $reason): void
    {
        throw NexusUnsupportedByBackendException::forBackend('journal');
    }

    private function append(Event $event): void
    {
        $this->eventStore->append($event);
        $this->history?->recorded($event);
    }

    private function nowSeconds(): float
    {
        return (float) $this->clock->now()->format('U.u');
    }
}
