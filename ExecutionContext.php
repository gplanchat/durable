<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Awaitable\ActivityAwaitable;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\TimerAwaitable;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\ChildWorkflowDeferredToMessenger;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\DurableChildWorkflowFailedException;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;
use Gplanchat\Durable\WorkflowIdReusePolicy;
use Symfony\Component\Uid\Uuid;

final class ExecutionContext
{
    /** @var array<string, \Gplanchat\Durable\Awaitable\Deferred> */
    private array $pendingActivities = [];

    /** @var array<string, \Gplanchat\Durable\Awaitable\Deferred> */
    private array $pendingTimers = [];

    private int $activitySlotIndex = 0;

    private int $timerSlotIndex = 0;

    private int $sideEffectSlotIndex = 0;

    private int $childWorkflowSlotIndex = 0;

    private int $signalWaitSlotIndex = 0;

    private int $updateWaitSlotIndex = 0;

    public function __construct(
        private readonly string $executionId,
        private readonly WorkflowHistorySourceInterface $historySource,
        private readonly WorkflowCommandBufferInterface $commandBuffer,
        private readonly ?ChildWorkflowRunner $childWorkflowRunner = null,
    ) {
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
            $activityId = (null !== $optId && '' !== $optId) ? $optId : (string) Uuid::v7();
        }
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        $this->pendingActivities[$activityId] = $deferred;

        $metadata = null !== $options ? $options->toMetadata() : [];
        if (null === $scheduled) {
            $now = microtime(true);
            $metadata['queued_at'] = $now;
            $metadata['first_queued_at'] = $now;
            $this->commandBuffer->scheduleActivity($activityId, $name, $payload, $metadata);
        }

        return new ActivityAwaitable($deferred->awaitable(), $activityId);
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
        $this->commandBuffer->recordSideEffect((string) Uuid::v7(), $result);
        $deferred->resolve($result);

        return $deferred->awaitable();
    }

    /**
     * @return Awaitable<mixed>
     */
    public function timer(float $seconds, string $timerSummary = ''): Awaitable
    {
        return $this->delay($seconds, $timerSummary);
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
        $childExecutionId = $scheduledId ?? ($options->workflowId ?? (string) Uuid::v7());

        if (null === $scheduledId && null !== $options->workflowId) {
            $this->assertChildWorkflowIdAllowed($options, $childExecutionId);
        }

        if (null === $scheduledId) {
            $this->commandBuffer->scheduleChildWorkflow(
                $childExecutionId,
                $childWorkflowType,
                $input,
                array_merge(
                    ['parentClosePolicy' => $options->parentClosePolicy, 'workflowId' => $options->workflowId],
                    $options->toSchedulingMetadata(),
                ),
            );
        }

        if (null !== $scheduledId && $this->childWorkflowRunner->defersChildStartToMessenger()) {
            return $deferred->awaitable();
        }

        try {
            $result = $this->childWorkflowRunner->runChild($childExecutionId, $childWorkflowType, $input, $this->executionId);
            $this->commandBuffer->completeWorkflow($result);
            $deferred->resolve($result);
        } catch (ChildWorkflowDeferredToMessenger) {
            return $deferred->awaitable();
        } catch (\Throwable $e) {
            $this->commandBuffer->failWorkflow($e);
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
     * Waits for a signal at signal slot N (order of signals in history).
     * In distributed mode, suspends until the signal is present in history.
     *
     * @return Awaitable<mixed>
     */
    public function waitSignal(string $signalName): Awaitable
    {
        $slot = $this->signalWaitSlotIndex++;
        $found = $this->historySource->findSignalForSlot($signalName, $slot);
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        if (null !== $found) {
            $deferred->resolve($found['payload']);

            return $deferred->awaitable();
        }

        return $deferred->awaitable();
    }

    /**
     * Waits for an update at update slot N (order of updates in history).
     *
     * @return Awaitable<mixed>
     */
    public function waitUpdate(string $updateName): Awaitable
    {
        $slot = $this->updateWaitSlotIndex++;
        $found = $this->historySource->findUpdateForSlot($updateName, $slot);
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        if (null !== $found) {
            $deferred->resolve($found['result']);

            return $deferred->awaitable();
        }

        return $deferred->awaitable();
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
        $this->rejectActivity($activityId, new ActivitySupersededException($activityId, $reason));

        return true;
    }

    /**
     * @return Awaitable<mixed>
     */
    public function delay(float $seconds, string $timerSummary = ''): Awaitable
    {
        $slotIndex = $this->timerSlotIndex++;
        $replay = $this->historySource->findTimerSlotResult($slotIndex);
        if (null !== $replay) {
            $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
            $deferred->resolve(null);

            return $deferred->awaitable();
        }

        $scheduled = $this->historySource->findScheduledTimerId($slotIndex);
        $timerId = $scheduled ?? (string) Uuid::v7();
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        $this->pendingTimers[$timerId] = $deferred;

        if (null === $scheduled) {
            $scheduledAt = microtime(true) + $seconds;
            $this->commandBuffer->startTimer($timerId, $scheduledAt, $timerSummary);
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
}
