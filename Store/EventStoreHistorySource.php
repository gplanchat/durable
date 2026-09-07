<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\VersionMarked;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\DurableChildWorkflowFailedException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\Port\WorkflowHistorySourceInterface;

/**
 * Implements WorkflowHistorySourceInterface by reading from EventStoreInterface.
 *
 * Used by the in-memory backend. The Temporal backend uses TemporalExecutionHistory instead.
 */
final class EventStoreHistorySource implements WorkflowHistorySourceInterface
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly string $executionId,
    ) {}

    public function findActivitySlotResult(int $slot): ?array
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
            if ($event instanceof ActivityCompleted) {
                $completedResults[$event->activityId()] = $event->result();
            }
            if ($event instanceof ActivityFailed) {
                // An `InProgress` failure (retry delegated to the Temporal server) is not
                // terminal: it must not settle the activity slot on replay.
                if (ActivityRetryState::InProgress !== $event->retryState()) {
                    $failedByActivityId[$event->activityId()] = DurableActivityFailedException::toThrowable($event);
                }
            }
            if ($event instanceof ActivityCatastrophicFailure) {
                $catastrophicByActivityId[$event->activityId()] = new DurableCatastrophicActivityFailureException($event);
            }
            if ($event instanceof ActivityCancelled) {
                $cancelledReasonByActivityId[$event->activityId()] = $event->reason();
            }
        }

        $activityId = $scheduledIds[$slot] ?? null;
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
            $reason = $cancelledReasonByActivityId[$activityId];

            return [
                'result' => null,
                'failed' => ActivityCancellationReason::WORKFLOW_CANCELLED === $reason
                    ? new WorkflowCancelledFailure($this->executionId, $reason)
                    : new ActivitySupersededException($activityId, $reason),
            ];
        }
        if (\array_key_exists($activityId, $completedResults)) {
            return ['result' => $completedResults[$activityId], 'failed' => null];
        }

        return null;
    }

    public function versionForChangeId(string $changeId): ?int
    {
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof VersionMarked && $event->changeId() === $changeId) {
                return $event->version();
            }
        }

        return null;
    }

    public function activityNameForSlot(int $slot): ?string
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof ActivityScheduled) {
                if ($index === $slot) {
                    // An empty string is not a name: it is "nothing recorded". The port
                    // promises null in that case, and the guard counts on it.
                    return '' === $event->activityName() ? null : $event->activityName();
                }
                ++$index;
            }
        }

        return null;
    }

    public function activityPayloadForSlot(int $slot): ?array
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof ActivityScheduled) {
                if ($index === $slot) {
                    // `payload()` returns the event's envelope; the activity's arguments are one
                    // slot of it. A non-array means "nothing to compare", not "empty array".
                    $arguments = $event->payload()['payload'] ?? null;

                    return \is_array($arguments) ? $arguments : null;
                }
                ++$index;
            }
        }

        return null;
    }

    public function childWorkflowInputForSlot(int $slot): ?array
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof ChildWorkflowScheduled) {
                if ($index === $slot) {
                    $input = $event->payload()['input'] ?? null;

                    return \is_array($input) ? $input : null;
                }
                ++$index;
            }
        }

        return null;
    }

    /**
     * Toujours null, et ce n'est pas un oubli.
     *
     * This backend refuses Nexus operations by design (DUR036): none of its histories carries one
     * the workflow would have scheduled. The only `NexusOperationScheduled` that can cross a stream
     * comes from the profiler's converter, which writes it for display, and that event carries only
     * the call site, never the payload. So there is nothing to compare.
     *
     * The guard applies where Nexus exists: {@see \Gplanchat\Bridge\Temporal\Worker\TemporalExecutionHistory}.
     */
    public function nexusOperationPayloadForSlot(int $slot): ?array
    {
        return null;
    }

    public function childWorkflowTypeForSlot(int $slot): ?string
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof ChildWorkflowScheduled) {
                if ($index === $slot) {
                    return '' === $event->childWorkflowType() ? null : $event->childWorkflowType();
                }
                ++$index;
            }
        }

        return null;
    }

    /**
     * This backend refuses to schedule a Nexus operation (DUR036): none of its journals carries
     * one, so the answer is always "nothing". This is not an implementation gap but the exact
     * consequence of that refusal.
     */
    public function nexusOperationSignatureForSlot(int $slot): ?string
    {
        return null;
    }

    public function findScheduledActivityId(int $slot): ?string
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof ActivityScheduled) {
                if ($index === $slot) {
                    return $event->activityId();
                }
                ++$index;
            }
        }

        return null;
    }

    public function findTimerSlotResult(int $slot): ?array
    {
        $scheduledIds = [];
        $completedIds = [];
        $cancelledReasons = [];
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof TimerScheduled) {
                $scheduledIds[] = $event->timerId();
            }
            if ($event instanceof TimerCompleted) {
                $completedIds[$event->timerId()] = true;
            }
            if ($event instanceof TimerCancelled) {
                $cancelledReasons[$event->timerId()] = $event->reason();
            }
        }

        $timerId = $scheduledIds[$slot] ?? null;
        if (null === $timerId) {
            return null;
        }

        if (ActivityCancellationReason::WORKFLOW_CANCELLED === ($cancelledReasons[$timerId] ?? null)) {
            return [
                'id' => $timerId,
                'scheduledAt' => 0.0,
                'failed' => new WorkflowCancelledFailure($this->executionId, ActivityCancellationReason::WORKFLOW_CANCELLED),
            ];
        }

        // A race loser simply stays unsettled: it never had a winner to announce.
        if (!isset($completedIds[$timerId])) {
            return null;
        }

        return ['id' => $timerId, 'scheduledAt' => 0.0, 'failed' => null];
    }

    public function findScheduledTimerId(int $slot): ?string
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof TimerScheduled) {
                if ($index === $slot) {
                    return $event->timerId();
                }
                ++$index;
            }
        }

        return null;
    }

    public function hasSideEffectForSlot(int $slot): bool
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof SideEffectRecorded) {
                if ($index === $slot) {
                    return true;
                }
                ++$index;
            }
        }

        return false;
    }

    public function findSideEffectForSlot(int $slot): mixed
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof SideEffectRecorded) {
                if ($index === $slot) {
                    return $event->result();
                }
                ++$index;
            }
        }

        return null;
    }

    public function findChildWorkflowForSlot(int $slot): ?array
    {
        $scheduledIds = [];
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof ChildWorkflowScheduled) {
                $scheduledIds[] = $event->childExecutionId();
            }
        }

        $childId = $scheduledIds[$slot] ?? null;
        if (null === $childId) {
            return null;
        }

        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof ChildWorkflowCompleted && $event->childExecutionId() === $childId) {
                return ['childExecutionId' => $childId, 'result' => $event->result(), 'failed' => null];
            }
            if ($event instanceof ChildWorkflowFailed && $event->childExecutionId() === $childId) {
                return [
                    'childExecutionId' => $childId,
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

    public function findScheduledChildExecutionId(int $slot): ?string
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof ChildWorkflowScheduled) {
                if ($index === $slot) {
                    return $event->childExecutionId();
                }
                ++$index;
            }
        }

        return null;
    }

    public function messageAt(int $index): ?array
    {
        $position = 0;
        $seen = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            // Signals and updates share the same cursor: what orders them is their rank in the
            // journal, not their kind.
            if ($event instanceof WorkflowSignalReceived) {
                if ($seen === $index) {
                    return [
                        'position' => $position,
                        'kind' => 'signal',
                        'name' => $event->signalName(),
                        'payload' => $event->signalPayload(),
                    ];
                }
                ++$seen;
            }
            if ($event instanceof WorkflowUpdateHandled) {
                if ($seen === $index) {
                    return [
                        'position' => $position,
                        'kind' => 'update',
                        'name' => $event->updateName(),
                        'payload' => $event->arguments(),
                    ];
                }
                ++$seen;
            }
            ++$position;
        }

        return null;
    }

    public function timerCompletionPosition(string $timerId): ?int
    {
        $position = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof TimerCompleted && $event->timerId() === $timerId) {
                return $position;
            }
            ++$position;
        }

        return null;
    }

    public function hasChildExecutionId(string $childExecutionId): bool
    {
        foreach ($this->eventStore->readStream($childExecutionId) as $_event) {
            return true;
        }

        return false;
    }

    public function hasChildExecutionCompletedSuccessfully(string $childExecutionId): bool
    {
        foreach ($this->eventStore->readStream($childExecutionId) as $event) {
            if ($event instanceof ExecutionCompleted) {
                return true;
            }
        }

        return false;
    }

    /**
     * Always null: this backend's buffer refuses to schedule a Nexus operation
     * ({@see \Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException}), so none can figure in
     * its journal. This is not an implementation still pending — there is nothing to read back
     * because there was never anything to write.
     */
    public function findNexusOperationSlotResult(int $slot): ?array
    {
        return null;
    }

    /** Always null, for the same reason as {@see findNexusOperationSlotResult()}. */
    public function findScheduledNexusOperation(int $slot): ?string
    {
        return null;
    }
}
