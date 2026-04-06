<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

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
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\DurableChildWorkflowFailedException;
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
    ) {
    }

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
                $failedByActivityId[$event->activityId()] = DurableActivityFailedException::toThrowable($event);
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
            return ['result' => null, 'failed' => new ActivitySupersededException($activityId, $cancelledReasonByActivityId[$activityId])];
        }
        if (\array_key_exists($activityId, $completedResults)) {
            return ['result' => $completedResults[$activityId], 'failed' => null];
        }

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
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof TimerScheduled) {
                $scheduledIds[] = $event->timerId();
            }
            if ($event instanceof TimerCompleted) {
                $completedIds[$event->timerId()] = true;
            }
        }

        $timerId = $scheduledIds[$slot] ?? null;
        if (null === $timerId || !isset($completedIds[$timerId])) {
            return null;
        }

        return ['id' => $timerId, 'scheduledAt' => 0.0];
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

    public function findSignalForSlot(string $signalName, int $slot): ?array
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof WorkflowSignalReceived) {
                if ($index === $slot) {
                    if ($event->signalName() !== $signalName) {
                        return null;
                    }

                    return ['payload' => $event->signalPayload()];
                }
                ++$index;
            }
        }

        return null;
    }

    public function findUpdateForSlot(string $updateName, int $slot): ?array
    {
        $index = 0;
        foreach ($this->eventStore->readStream($this->executionId) as $event) {
            if ($event instanceof WorkflowUpdateHandled) {
                if ($index === $slot) {
                    if ($event->updateName() !== $updateName) {
                        return null;
                    }

                    return ['result' => $event->result()];
                }
                ++$index;
            }
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
}
