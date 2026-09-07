<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Awaitable\ActivityAwaitable;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\NexusOperationAwaitable;
use Gplanchat\Durable\Awaitable\TimerAwaitable;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\ChildWorkflowStartDeferred;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\DurableChildWorkflowFailedException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;
use Gplanchat\Durable\Uuid\NativeUuidV7Generator;
use Gplanchat\Durable\Uuid\UuidGeneratorInterface;
use Gplanchat\Durable\Versioning\ChangePoint;
use Gplanchat\Durable\Workflow\QueryHandlerRegistry;

final class ExecutionContext
{
    private ?QueryHandlerRegistry $queryHandlers = null;

    /** @var array<string, \Gplanchat\Durable\Awaitable\Deferred> */
    private array $pendingActivities = [];

    /** @var array<string, \Gplanchat\Durable\Awaitable\Deferred> */
    private array $pendingNexusOperations = [];

    /** @var array<string, \Gplanchat\Durable\Awaitable\Deferred> */
    private array $pendingTimers = [];

    private int $activitySlotIndex = 0;

    private int $nexusOperationSlotIndex = 0;

    private int $timerSlotIndex = 0;

    private int $sideEffectSlotIndex = 0;

    private int $childWorkflowSlotIndex = 0;

    /**
     * Rank of the next unapplied message. Rebuilt from zero on every pass, advanced by the same
     * rule over the same log: that is what makes a condition's verdict reproducible.
     */
    private int $messageCursor = 0;

    public function __construct(
        private readonly string $executionId,
        private readonly WorkflowHistorySourceInterface $historySource,
        private readonly WorkflowCommandBufferInterface $commandBuffer,
        private readonly ?ChildWorkflowRunnerInterface $childWorkflowRunner = null,
        private readonly ?UuidGeneratorInterface $uuidGenerator = null,
        /**
         * The updates the pass receives outside the log. They come after everything that is
         * recorded: having no position yet, they take the one of their arrival.
         *
         * @var list<\Gplanchat\Durable\Workflow\PendingUpdate>
         */
        private readonly array $pendingUpdates = [],
    ) {}

    /**
     * The query handlers of this execution.
     *
     * Carried here because a workflow never receives the context: that is what puts the query
     * plumbing out of its reach without making it unreachable to the engine.
     *
     * @internal
     */
    public function queryHandlers(): QueryHandlerRegistry
    {
        return $this->queryHandlers ??= new QueryHandlerRegistry();
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return Awaitable<mixed>
     */
    public function activity(string $name, array $payload = [], ?ActivityOptions $options = null): Awaitable
    {
        $slotIndex = $this->activitySlotIndex++;
        $this->refuseActivityDivergence($slotIndex, $name);
        $replay = $this->historySource->findActivitySlotResult($slotIndex);
        if (null !== $replay) {
            $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
            if (null !== $replay['failed']) {
                $deferred->reject($replay['failed']);
            } else {
                $deferred->resolve($replay['result']);
            }
            $replayActivityId = $this->historySource->findScheduledActivityId($slotIndex) ?? '';

            return new ActivityAwaitable($deferred->awaitable(), $replayActivityId);
        }

        $scheduled = $this->historySource->findScheduledActivityId($slotIndex);
        if (null !== $scheduled) {
            $activityId = $scheduled;
        } else {
            $optId = $options?->activityId;
            $activityId = (null !== $optId && '' !== $optId) ? $optId : $this->uuid();
        }
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        $this->pendingActivities[$activityId] = $deferred;

        if (null === $scheduled) {
            // The options travel as they are; the enqueue timestamp belongs to the backend,
            // which alone owns a clock.
            $this->commandBuffer->scheduleActivity($activityId, $name, $payload, $options);
        }

        return new ActivityAwaitable($deferred->awaitable(), $activityId);
    }

    /**
     * Schedules a Nexus operation and returns the wait for its result.
     *
     * Same slot discipline as {@see activity()}: the rank of the call identifies the operation
     * from one replay pass to the next. The difference in consequence is worth stating — an
     * activity rescheduled by mistake lands back on a worker of one's own, a Nexus operation
     * goes out to a third party, where the duplicate is theirs.
     *
     * @param array<string, mixed> $payload
     *
     * @return Awaitable<mixed>
     */
    public function nexusOperation(
        NexusEndpoint $endpoint,
        NexusService $service,
        NexusOperationName $operation,
        array $payload = [],
        ?NexusOperationTimeouts $timeouts = null,
        ?NexusOperationHeaders $headers = null,
    ): Awaitable {
        $slotIndex = $this->nexusOperationSlotIndex++;
        $this->refuseDivergence(
            'Nexus operation',
            $slotIndex,
            $this->historySource->nexusOperationSignatureForSlot($slotIndex),
            \sprintf('%s/%s/%s', $endpoint->name(), $service->name(), $operation->name()),
        );
        $scheduled = $this->historySource->findScheduledNexusOperation($slotIndex);
        $operationId = $scheduled ?? $this->uuid();

        $replay = $this->historySource->findNexusOperationSlotResult($slotIndex);
        if (null !== $replay) {
            $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
            if (null !== $replay['failed']) {
                $deferred->reject($replay['failed']);
            } else {
                $deferred->resolve($replay['result']);
            }

            return new NexusOperationAwaitable($deferred->awaitable(), $operationId);
        }

        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        $this->pendingNexusOperations[$operationId] = $deferred;

        if (null === $scheduled) {
            $this->commandBuffer->scheduleNexusOperation(
                $operationId,
                $endpoint,
                $service,
                $operation,
                $payload,
                $timeouts ?? NexusOperationTimeouts::none(),
                $headers ?? NexusOperationHeaders::none(),
            );
        }

        return new NexusOperationAwaitable($deferred->awaitable(), $operationId);
    }

    /**
     * Declares that the workflow's behaviour changed here, and returns the one that applies to
     * THIS execution.
     *
     * The answer is frozen on the first encounter and read back from the log afterwards: an
     * execution in flight keeps its behaviour whatever is deployed after it. That is what
     * separates versioning from guesswork.
     *
     * Two change points are independent — they are keyed by their identifier, not by a
     * position —, so an execution can be on the old side of one and on the new side of the
     * other.
     *
     * @param string $changeId     the name of this change point, stable over time
     * @param int    $minSupported the oldest version this code can still play
     * @param int    $maxSupported the most recent one, the one a fresh execution will take
     */
    public function version(string $changeId, int $minSupported, int $maxSupported): int
    {
        $recorded = $this->historySource->versionForChangeId($changeId);
        if (null !== $recorded) {
            return $recorded;
        }

        // No marker, and recorded work still ahead: this execution went through here before
        // the change point existed. So it keeps the old behaviour, and nothing is written —
        // the answer is deduced from the history rather than added to it, which makes it
        // stable by construction.
        if ($this->hasRecordedWorkAhead()) {
            return ChangePoint::DEFAULT_VERSION;
        }

        $this->commandBuffer->recordVersion($changeId, $maxSupported);

        return $maxSupported;
    }

    /**
     * Does the log still carry work this pass has not reached?
     *
     * This is the "currently replaying" signal this engine did not have, deduced from what the
     * port already exposes rather than bolted on beside it: if the next slot of one of the types
     * is recorded, the current call sits inside the replayed prefix. Otherwise the execution has
     * reached the end of its history and what it does now is new.
     *
     * Deduced, therefore deterministic: two replays of the same history answer alike, which is
     * the only property versioning needs.
     *
     * Side effects are not consulted: `findSideEffectForSlot()` returns `mixed`, and a recorded
     * value can legitimately be `null` — "nothing here" cannot be told apart from "here, the
     * value null". A workflow whose only work before a change point is a side effect will
     * therefore be treated as new. That is the hole, it is narrow, and it is written down.
     */
    private function hasRecordedWorkAhead(): bool
    {
        return null !== $this->historySource->findScheduledActivityId($this->activitySlotIndex)
            || null !== $this->historySource->findScheduledTimerId($this->timerSlotIndex)
            || null !== $this->historySource->findScheduledChildExecutionId($this->childWorkflowSlotIndex)
            || null !== $this->historySource->findScheduledNexusOperation($this->nexusOperationSlotIndex);
    }

    /**
     * Refuses to settle a slot with a record that is not its own.
     *
     * Slots are positional: slot N is the N-th call, not the N-th call *to that particular
     * activity*. Inserting a call before another therefore shifts everything that follows, and
     * replay used to return the neighbour's recorded result — without a word. Measured against
     * a real server: the execution finished **successfully** carrying the wrong value.
     *
     * The comparison relies only on what the history already carries. Adding a field to the
     * events would have left unguarded exactly the executions the guard protects: the old
     * ones.
     *
     * A slot nobody recorded is not a divergence — it is a workflow growing, and refusing it
     * would break the normal case.
     *
     * @throws WorkflowTaskFailure when the code asks for something other than what the log holds
     */
    private function refuseActivityDivergence(int $slotIndex, string $requested): void
    {
        $this->refuseDivergence('activity', $slotIndex, $this->historySource->activityNameForSlot($slotIndex), $requested);
    }

    /**
     * The rule, once, for the three slot types that carry an identity.
     *
     * `$recorded` at null means "the history said nothing there" — either the slot is new, or
     * the log does not carry that identity. In both cases there is nothing to compare, and
     * refusing would break the normal case. Timers are in that case by nature: their due time
     * is absolute and their label optional (probe 1.4).
     *
     * @throws WorkflowTaskFailure when the code asks for something other than what the log holds
     */
    private function refuseDivergence(string $slotKind, int $slotIndex, ?string $recorded, string $requested): void
    {
        if (null === $recorded || $recorded === $requested) {
            return;
        }

        throw new WorkflowTaskFailure(\sprintf(
            'Replay divergence at %s slot %d of execution "%s": history recorded "%s", code scheduled "%s". '
            . 'This history was written by a different version of the workflow.',
            $slotKind,
            $slotIndex,
            $this->executionId,
            $recorded,
            $requested,
        ));
    }

    /**
     * Executes a potentially non-deterministic closure once; on replay, reuses the result recorded in history.
     *
     * @param \Closure(): mixed $closure
     *
     * @return Awaitable<mixed>
     */
    public function sideEffect(\Closure $closure): Awaitable
    {
        $slotIndex = $this->sideEffectSlotIndex++;
        $replayResult = $this->historySource->findSideEffectForSlot($slotIndex);
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        if (null !== $replayResult) {
            $deferred->resolve($replayResult);

            return $deferred->awaitable();
        }

        $result = $closure();
        $this->commandBuffer->recordSideEffect($this->uuid(), $result);
        $deferred->resolve($result);

        return $deferred->awaitable();
    }

    /**
     * @return Awaitable<mixed>
     */
    public function timer(Duration $delay, string $timerSummary = ''): Awaitable
    {
        return $this->delay($delay, $timerSummary);
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @throws ContinueAsNewRequested always
     */
    public function continueAsNew(string $workflowType, array $payload = [], ?ContinueAsNewOptions $options = null): never
    {
        throw new ContinueAsNewRequested($workflowType, $payload, $options);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return Awaitable<mixed>
     */
    public function executeChildWorkflow(string $childWorkflowType, array $input = [], ?ChildWorkflowOptions $options = null): Awaitable
    {
        if (null === $this->childWorkflowRunner) {
            throw new \LogicException('ChildWorkflowRunner is not configured on ExecutionContext.');
        }

        $options ??= ChildWorkflowOptions::defaults();

        $slotIndex = $this->childWorkflowSlotIndex++;
        $this->refuseDivergence(
            'child workflow',
            $slotIndex,
            $this->historySource->childWorkflowTypeForSlot($slotIndex),
            $childWorkflowType,
        );
        $replay = $this->historySource->findChildWorkflowForSlot($slotIndex);
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        if (null !== $replay) {
            if (null !== $replay['failed']) {
                $deferred->reject($replay['failed']);
            } else {
                $deferred->resolve($replay['result']);
            }

            return $deferred->awaitable();
        }

        $scheduledId = $this->historySource->findScheduledChildExecutionId($slotIndex);
        $childExecutionId = $scheduledId ?? ($options->workflowId ?? $this->uuid());

        if (null === $scheduledId && null !== $options->workflowId) {
            $this->assertChildWorkflowIdAllowed($options, $childExecutionId);
        }

        if (null === $scheduledId) {
            $this->commandBuffer->scheduleChildWorkflow($childExecutionId, $childWorkflowType, $input, $options);
        }

        if (null !== $scheduledId && $this->childWorkflowRunner->defersChildStart()) {
            return $deferred->awaitable();
        }

        try {
            $result = $this->childWorkflowRunner->runChild($childExecutionId, $childWorkflowType, $input, $this->executionId);
            // The CHILD's outcome, not the current run's: completeWorkflow() here closed the
            // parent's log with the child's result, and never wrote the ChildWorkflowCompleted
            // that findChildWorkflowForSlot() looks for on replay — so the child was re-run on
            // every resume of the parent.
            $this->commandBuffer->completeChildWorkflow($childExecutionId, $result);
            $deferred->resolve($result);
        } catch (ChildWorkflowStartDeferred) {
            return $deferred->awaitable();
        } catch (\Throwable $e) {
            $this->commandBuffer->failChildWorkflow($childExecutionId, $e);
            $deferred->reject(new DurableChildWorkflowFailedException(
                $childExecutionId,
                $e->getMessage(),
                (int) $e->getCode(),
                $e,
            ));
        }

        return $deferred->awaitable();
    }

    /**
     * Applies the next recorded message, if one is left before `$beforePosition`.
     *
     * One by one, never in a batch: a message recorded after a deadline fired must not settle
     * the condition that deadline bounded, and a condition satisfied by the first of two
     * messages must resume having seen only that one. Both follow from the same rule —
     * the verdict is a position in the log (ADR DUR035).
     *
     * `pending` carries the out-of-log update when the message is one, and null when the message
     * is read back from the log: that is what separates "producing the outcome" from "rebuilding
     * the state".
     *
     * @return array{kind: 'signal'|'update', name: string, payload: array<string, mixed>, pending: \Gplanchat\Durable\Workflow\PendingUpdate|null}|null
     */
    public function nextMessage(?int $beforePosition = null): ?array
    {
        $message = $this->historySource->messageAt($this->messageCursor);
        if (null !== $message) {
            if (null !== $beforePosition && $message['position'] > $beforePosition) {
                return null;
            }

            ++$this->messageCursor;

            return [
                'kind' => $message['kind'],
                'name' => $message['name'],
                'payload' => $message['payload'],
                'pending' => null,
            ];
        }

        // The log is exhausted: what is left are the updates that arrived outside the log for
        // this pass.
        $recorded = $this->countRecordedMessages();
        $pending = $this->pendingUpdates[$this->messageCursor - $recorded] ?? null;
        if (null === $pending) {
            return null;
        }

        ++$this->messageCursor;

        return ['kind' => 'update', 'name' => $pending->name, 'payload' => $pending->arguments, 'pending' => $pending];
    }

    private function countRecordedMessages(): int
    {
        $count = 0;
        while (null !== $this->historySource->messageAt($count)) {
            ++$count;
        }

        return $count;
    }

    /**
     * Records the outcome of an update that has just been handled, at the position where it was.
     *
     * @param array<string, mixed> $arguments
     */
    public function recordUpdateHandled(string $updateName, array $arguments, mixed $result, ?FailureEnvelope $failure): void
    {
        $this->commandBuffer->recordUpdateHandled($updateName, $arguments, $result, $failure);
    }

    /**
     * Position at which this timer's firing is recorded, or null when it has not fired.
     */
    public function timerCompletionPosition(string $timerId): ?int
    {
        return $this->historySource->timerCompletionPosition($timerId);
    }

    /**
     * Cancels a pending activity (best effort).
     */
    public function cancelScheduledActivity(string $activityId, string $reason): bool
    {
        if (!isset($this->pendingActivities[$activityId])) {
            return false;
        }

        $this->commandBuffer->cancelActivity($activityId, $reason);
        $this->rejectActivity($activityId, ActivityCancellationReason::WORKFLOW_CANCELLED === $reason
            ? new WorkflowCancelledFailure($this->executionId, $reason)
            : new ActivitySupersededException($activityId, $reason));

        return true;
    }

    /**
     * Withdraws a Nexus operation still in flight (best effort).
     *
     * As with an activity, the request goes out to the endpoint with no guarantee it will be
     * honoured: what is guaranteed is that its answer will no longer wake this execution up.
     * Cancelling the workflow rejects the wait so that it can compensate; a race loser is
     * simply left unsettled.
     */
    public function cancelScheduledNexusOperation(string $operationId, string $reason): bool
    {
        if (!isset($this->pendingNexusOperations[$operationId])) {
            return false;
        }

        $deferred = $this->pendingNexusOperations[$operationId];
        unset($this->pendingNexusOperations[$operationId]);
        $this->commandBuffer->cancelNexusOperation($operationId, $reason);

        if (ActivityCancellationReason::WORKFLOW_CANCELLED === $reason) {
            $deferred->reject(new WorkflowCancelledFailure($this->executionId, $reason));
        }

        return true;
    }

    /**
     * Cancels a timer still pending (best effort).
     *
     * The timer will never be settled: it is removed from the pending ones so that
     * {@see resolveTimer()} becomes a no-op, and the log receives a
     * {@see \Gplanchat\Durable\Event\TimerCancelled}.
     */
    public function cancelScheduledTimer(string $timerId, string $reason): bool
    {
        if (!isset($this->pendingTimers[$timerId])) {
            return false;
        }

        $deferred = $this->pendingTimers[$timerId];
        unset($this->pendingTimers[$timerId]);
        $this->commandBuffer->cancelTimer($timerId, $reason);

        // A race loser is simply left unsettled; a workflow cancellation must on the contrary
        // throw, so that the workflow can compensate.
        if (ActivityCancellationReason::WORKFLOW_CANCELLED === $reason) {
            $deferred->reject(new WorkflowCancelledFailure($this->executionId, $reason));
        }

        return true;
    }

    /**
     * @return Awaitable<mixed>
     */
    public function delay(Duration $delay, string $timerSummary = ''): Awaitable
    {
        $slotIndex = $this->timerSlotIndex++;
        $replay = $this->historySource->findTimerSlotResult($slotIndex);
        if (null !== $replay) {
            $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
            if (null !== ($replay['failed'] ?? null)) {
                $deferred->reject($replay['failed']);
            } else {
                $deferred->resolve(null);
            }

            return new TimerAwaitable($deferred->awaitable(), $replay['id']);
        }

        $scheduled = $this->historySource->findScheduledTimerId($slotIndex);
        $timerId = $scheduled ?? $this->uuid();
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        $this->pendingTimers[$timerId] = $deferred;

        if (null === $scheduled) {
            // The delay travels as it is: turning a duration into a due time requires a clock,
            // and the core has none — that is a backend decision.
            $this->commandBuffer->startTimer($timerId, $delay, $timerSummary);
        }

        return new TimerAwaitable($deferred->awaitable(), $timerId);
    }

    /**
     * @return array<string, \Gplanchat\Durable\Awaitable\Deferred>
     */
    public function pendingTimers(): array
    {
        return $this->pendingTimers;
    }

    public function resolveTimer(string $timerId): void
    {
        $deferred = $this->pendingTimers[$timerId] ?? null;
        if (null !== $deferred) {
            $deferred->resolve(null);
            unset($this->pendingTimers[$timerId]);
        }
    }

    /**
     * @return array<string, \Gplanchat\Durable\Awaitable\Deferred>
     */
    /**
     * The Nexus operations still in flight, by identifier.
     *
     * Mirror of {@see pendingActivities()}: that is how the outcome of an operation, read from
     * the history, finds again the wait it has to settle.
     *
     * @return array<string, \Gplanchat\Durable\Awaitable\Deferred>
     */
    public function pendingNexusOperations(): array
    {
        return $this->pendingNexusOperations;
    }

    public function pendingActivities(): array
    {
        return $this->pendingActivities;
    }

    public function resolveActivity(string $activityId, mixed $result): void
    {
        $deferred = $this->pendingActivities[$activityId] ?? null;
        if (null !== $deferred) {
            $deferred->resolve($result);
            unset($this->pendingActivities[$activityId]);
        }
    }

    public function rejectActivity(string $activityId, \Throwable $reason): void
    {
        $deferred = $this->pendingActivities[$activityId] ?? null;
        if (null !== $deferred) {
            $deferred->reject($reason);
            unset($this->pendingActivities[$activityId]);
        }
    }

    private function assertChildWorkflowIdAllowed(ChildWorkflowOptions $options, string $childExecutionId): void
    {
        if (WorkflowIdReusePolicy::AllowDuplicate === $options->workflowIdReusePolicy) {
            return;
        }

        if (!$this->historySource->hasChildExecutionId($childExecutionId)) {
            return;
        }

        if (WorkflowIdReusePolicy::RejectDuplicate === $options->workflowIdReusePolicy) {
            throw new \InvalidArgumentException(\sprintf('Child workflow execution id %s is already used in the event store.', $childExecutionId));
        }

        if ($this->historySource->hasChildExecutionCompletedSuccessfully($childExecutionId)) {
            throw new \InvalidArgumentException(\sprintf('Child workflow execution id %s already completed successfully; reuse is not allowed with AllowDuplicateFailedOnly.', $childExecutionId));
        }
    }

    private function uuid(): string
    {
        return ($this->uuidGenerator ?? new NativeUuidV7Generator())->generate();
    }
}
