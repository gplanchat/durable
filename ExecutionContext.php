<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Awaitable\ActivityAwaitable;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\TimerAwaitable;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\ChildWorkflowDeferredToMessenger;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\DurableChildWorkflowFailedException;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
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
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityTransportInterface $activityTransport,
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
        $replay = $this->findReplayResultForSlot($slotIndex);
        if (null !== $replay) {
            $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
            if (null !== $replay['failed']) {
                $deferred->reject($replay['failed']);
            } else {
                $deferred->resolve($replay['result']);
            }
            $replayActivityId = $this->findScheduledActivityIdForSlot($slotIndex) ?? '';

            return new ActivityAwaitable($deferred->awaitable(), $replayActivityId);
        }

        $scheduled = $this->findScheduledActivityIdForSlot($slotIndex);
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
        }

        if (null === $scheduled) {
            $event = new ActivityScheduled(
                $this->executionId,
                $activityId,
                $name,
                $payload,
                $metadata,
            );
            $this->eventStore->append($event);
            $this->activityTransport->enqueue(new ActivityMessage(
                $this->executionId,
                $activityId,
                $name,
                $payload,
                $metadata,
            ));
        }

        return new ActivityAwaitable($deferred->awaitable(), $activityId);
    }

    /**
     * Exécute une closure potentiellement non déterministe une seule fois ; au replay, réutilise le résultat
     * enregistré dans le journal (équivalent Temporal {@link https://docs.temporal.io/develop/php/side-effects}).
     *
     * @param \Closure(): mixed $closure
     *
     * @return Awaitable<mixed>
     */
    public function sideEffect(\Closure $closure): Awaitable
    {
        $slotIndex = $this->sideEffectSlotIndex++;
        $replayResult = $this->findReplaySideEffectResultForSlot($slotIndex);
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        if (null !== $replayResult) {
            $deferred->resolve($replayResult);

            return $deferred->awaitable();
        }

        $result = $closure();
        $this->eventStore->append(new SideEffectRecorded(
            $this->executionId,
            (string) Uuid::v7(),
            $result,
        ));
        $deferred->resolve($result);

        return $deferred->awaitable();
    }

    /**
     * Timer durable : alias sémantique de {@see delay()} (Temporal {@link https://docs.temporal.io/develop/php/timers}).
     *
     * @return Awaitable<mixed>
     */
    public function timer(float $seconds, string $timerSummary = ''): Awaitable
    {
        return $this->delay($seconds, $timerSummary);
    }

    /**
     * Enchaîne un nouveau run avec un historique vierge (Temporal continue-as-new).
     *
     * @param array<string, mixed> $payload
     *
     * @throws ContinueAsNewRequested toujours — le moteur append {@see Event\WorkflowContinuedAsNew}
     */
    public function continueAsNew(string $workflowType, array $payload = [], ?ContinueAsNewOptions $options = null): never
    {
        throw new ContinueAsNewRequested($workflowType, $payload, $options);
    }

    /**
     * Exécute un workflow enfant enregistré dans {@see WorkflowRegistry} ; le journal parent reçoit
     * {@see ChildWorkflowScheduled} puis {@see ChildWorkflowCompleted} ou {@see ChildWorkflowFailed}.
     *
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
        $replay = $this->findReplayChildWorkflowForSlot($slotIndex);
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        if (null !== $replay) {
            if (null !== $replay['failed']) {
                $deferred->reject($replay['failed']);
            } else {
                $deferred->resolve($replay['result']);
            }

            return $deferred->awaitable();
        }

        $scheduledId = $this->findScheduledChildExecutionIdForSlot($slotIndex);
        $childExecutionId = $scheduledId ?? ($options->workflowId ?? (string) Uuid::v7());

        if (null === $scheduledId && null !== $options->workflowId) {
            $this->assertChildWorkflowIdAllowed($options, $childExecutionId);
        }

        if (null === $scheduledId) {
            $this->eventStore->append(new ChildWorkflowScheduled(
                $this->executionId,
                $childExecutionId,
                $childWorkflowType,
                $input,
                $options->parentClosePolicy,
                $options->workflowId,
                $options->toSchedulingMetadata(),
            ));
        }

        if (null !== $scheduledId && $this->childWorkflowRunner->defersChildStartToMessenger()) {
            return $deferred->awaitable();
        }

        try {
            $result = $this->childWorkflowRunner->runChild($childExecutionId, $childWorkflowType, $input, $this->executionId);
            $this->eventStore->append(new ChildWorkflowCompleted($this->executionId, $childExecutionId, $result));
            $deferred->resolve($result);
        } catch (ChildWorkflowDeferredToMessenger) {
            return $deferred->awaitable();
        } catch (\Throwable $e) {
            $this->eventStore->append(new ChildWorkflowFailed(
                $this->executionId,
                $childExecutionId,
                $e->getMessage(),
                (int) $e->getCode(),
            ));
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
     * Attend le signal n° slot (ordre des {@see WorkflowSignalReceived} dans le journal).
     * En mode distribué, suspend tant que le signal n’est pas présent dans l’historique.
     *
     * @return Awaitable<mixed>
     */
    public function waitSignal(string $signalName): Awaitable
    {
        $slot = $this->signalWaitSlotIndex++;
        $evt = $this->findWorkflowSignalReceivedForSlot($slot);
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        if (null !== $evt) {
            if ($evt->signalName() !== $signalName) {
                throw new DurableWorkflowAlgorithmFailureException(\sprintf('Signal replay mismatch: expected %s, history has %s', $signalName, $evt->signalName()));
            }
            $deferred->resolve($evt->signalPayload());

            return $deferred->awaitable();
        }

        return $deferred->awaitable();
    }

    /**
     * Attend la mise à jour n° slot (ordre des {@see WorkflowUpdateHandled} dans le journal).
     *
     * @return Awaitable<mixed> résultat enregistré dans l’historique pour cette update
     */
    public function waitUpdate(string $updateName): Awaitable
    {
        $slot = $this->updateWaitSlotIndex++;
        $evt = $this->findWorkflowUpdateHandledForSlot($slot);
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        if (null !== $evt) {
            if ($evt->updateName() !== $updateName) {
                throw new DurableWorkflowAlgorithmFailureException(\sprintf('Update replay mismatch: expected %s, history has %s', $updateName, $evt->updateName()));
            }
            $deferred->resolve($evt->result());

            return $deferred->awaitable();
        }

        return $deferred->awaitable();
    }

    /**
     * Retire l'activité de la file si elle n'a pas encore été dequeue (best effort).
     */
    public function cancelScheduledActivity(string $activityId, string $reason): bool
    {
        if (!$this->activityTransport->removePendingFor($this->executionId, $activityId)) {
            return false;
        }

        $this->eventStore->append(new ActivityCancelled($this->executionId, $activityId, $reason));
        $this->rejectActivity($activityId, new ActivitySupersededException($activityId, $reason));

        return true;
    }

    /**
     * @return Awaitable<mixed>
     */
    public function delay(float $seconds, string $timerSummary = ''): Awaitable
    {
        $slotIndex = $this->timerSlotIndex++;
        $replay = $this->findReplayTimerForSlot($slotIndex);
        if (null !== $replay) {
            $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
            $deferred->resolve(null);

            return $deferred->awaitable();
        }

        $scheduled = $this->findScheduledTimerIdForSlot($slotIndex);
        $timerId = $scheduled ?? (string) Uuid::v7();
        $deferred = new \Gplanchat\Durable\Awaitable\Deferred();
        $this->pendingTimers[$timerId] = $deferred;

        if (null === $scheduled) {
            $scheduledAt = microtime(true) + $seconds;
            $this->eventStore->append(new TimerScheduled($this->executionId, $timerId, $scheduledAt, $timerSummary));
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
     * @return array{id: string, scheduledAt: float}|null
     */
    private function findReplaySideEffectResultForSlot(int $slotIndex): mixed
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof SideEffectRecorded) {
                if ($index === $slotIndex) {
                    return $event->result();
                }
                ++$index;
            }
        }

        return null;
    }

    /**
     * @return array{id: string, scheduledAt: float}|null
     */
    private function findReplayTimerForSlot(int $slotIndex): ?array
    {
        $scheduledIds = [];
        $completedIds = [];
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof TimerScheduled) {
                $scheduledIds[] = $event->timerId();
            }
            if ($event instanceof TimerCompleted) {
                $completedIds[$event->timerId()] = true;
            }
        }

        $timerId = $scheduledIds[$slotIndex] ?? null;
        if (null === $timerId || !isset($completedIds[$timerId])) {
            return null;
        }

        return ['id' => $timerId, 'scheduledAt' => 0.0];
    }

    private function findScheduledTimerIdForSlot(int $slotIndex): ?string
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof TimerScheduled) {
                if ($index === $slotIndex) {
                    return $event->timerId();
                }
                ++$index;
            }
        }

        return null;
    }

    /**
     * @return array{result: mixed, failed: \Throwable|null}|null Null when neither completed nor failed
     */
    private function findReplayResultForSlot(int $slotIndex): ?array
    {
        $scheduledIds = [];
        $completedResults = [];
        $failedByActivityId = [];
        $catastrophicByActivityId = [];
        $cancelledReasonByActivityId = [];
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof ActivityScheduled) {
                $scheduledIds[] = $event->activityId();
            }
            if ($event instanceof Event\ActivityCompleted) {
                $completedResults[$event->activityId()] = $event->result();
            }
            if ($event instanceof ActivityFailed) {
                $failedByActivityId[$event->activityId()] = DurableActivityFailedException::toThrowable($event);
            }
            if ($event instanceof ActivityCatastrophicFailure) {
                $catastrophicByActivityId[$event->activityId()] = new DurableCatastrophicActivityFailureException($event);
            }
            if ($event instanceof ActivityCancelled) {
                $cancelledReasonByActivityId[$event->activityId()] = $event->reason();
            }
        }

        $activityId = $scheduledIds[$slotIndex] ?? null;
        if (null === $activityId) {
            return null;
        }

        if (isset($catastrophicByActivityId[$activityId])) {
            return ['result' => null, 'failed' => $catastrophicByActivityId[$activityId]];
        }

        if (isset($failedByActivityId[$activityId])) {
            return ['result' => null, 'failed' => $failedByActivityId[$activityId]];
        }

        if (isset($cancelledReasonByActivityId[$activityId])) {
            return ['result' => null, 'failed' => new ActivitySupersededException($activityId, $cancelledReasonByActivityId[$activityId])];
        }

        if (\array_key_exists($activityId, $completedResults)) {
            return ['result' => $completedResults[$activityId], 'failed' => null];
        }

        return null;
    }

    private function findScheduledActivityIdForSlot(int $slotIndex): ?string
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof ActivityScheduled) {
                if ($index === $slotIndex) {
                    return $event->activityId();
                }
                ++$index;
            }
        }

        return null;
    }

    private function assertChildWorkflowIdAllowed(ChildWorkflowOptions $options, string $childExecutionId): void
    {
        if (WorkflowIdReusePolicy::AllowDuplicate === $options->workflowIdReusePolicy) {
            return;
        }

        $hasAnyEvent = false;
        $hasSuccessfulCompletion = false;
        foreach ($this->eventStore->readStream($childExecutionId) as $event) {
            $hasAnyEvent = true;
            if ($event instanceof ExecutionCompleted) {
                $hasSuccessfulCompletion = true;
            }
        }

        if (!$hasAnyEvent) {
            return;
        }

        if (WorkflowIdReusePolicy::RejectDuplicate === $options->workflowIdReusePolicy) {
            throw new \InvalidArgumentException(\sprintf('Child workflow execution id %s is already used in the event store.', $childExecutionId));
        }

        // AllowDuplicateFailedOnly : autoriser seulement si aucune exécution **réussie** enregistrée sur cet id.
        if ($hasSuccessfulCompletion) {
            throw new \InvalidArgumentException(\sprintf('Child workflow execution id %s already completed successfully; reuse is not allowed with AllowDuplicateFailedOnly.', $childExecutionId));
        }
    }

    /**
     * @return array{result: mixed, failed: \Throwable|null}|null
     */
    private function findReplayChildWorkflowForSlot(int $slotIndex): ?array
    {
        $scheduledIds = [];
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof ChildWorkflowScheduled) {
                $scheduledIds[] = $event->childExecutionId();
            }
        }

        $childId = $scheduledIds[$slotIndex] ?? null;
        if (null === $childId) {
            return null;
        }

        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof ChildWorkflowCompleted && $event->childExecutionId() === $childId) {
                return ['result' => $event->result(), 'failed' => null];
            }
            if ($event instanceof ChildWorkflowFailed && $event->childExecutionId() === $childId) {
                return [
                    'result' => null,
                    'failed' => new DurableChildWorkflowFailedException(
                        $childId,
                        $event->failureMessage(),
                        $event->failureCode(),
                        null,
                        $event->workflowFailureKind(),
                        $event->workflowFailureClass(),
                        $event->workflowFailureContext(),
                    ),
                ];
            }
        }

        return null;
    }

    private function findScheduledChildExecutionIdForSlot(int $slotIndex): ?string
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof ChildWorkflowScheduled) {
                if ($index === $slotIndex) {
                    return $event->childExecutionId();
                }
                ++$index;
            }
        }

        return null;
    }

    private function findWorkflowSignalReceivedForSlot(int $slot): ?WorkflowSignalReceived
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof WorkflowSignalReceived) {
                if ($index === $slot) {
                    return $event;
                }
                ++$index;
            }
        }

        return null;
    }

    private function findWorkflowUpdateHandledForSlot(int $slot): ?WorkflowUpdateHandled
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof WorkflowUpdateHandled) {
                if ($index === $slot) {
                    return $event;
                }
                ++$index;
            }
        }

        return null;
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
}
