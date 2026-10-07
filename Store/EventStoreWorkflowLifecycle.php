<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\AwaitableInspector;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\WorkflowCancellationDelivered;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\WorkflowFailureClassifier;
use Gplanchat\Durable\Observation\WaitReason;
use Gplanchat\Durable\ParentClosureReason;
use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;
use Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;

/**
 * The in-memory backend's lifecycle outcomes: journal + closing of the children, and signalling
 * to the caller by exception (suspension, cancellation, unhandled failure).
 */
final readonly class EventStoreWorkflowLifecycle implements WorkflowLifecycleInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private ?ParentChildWorkflowCoordinatorInterface $parentChildCoordinator = null,
    ) {}

    public function onBeforeRun(ExecutionId $executionId): void
    {
        // Nothing to pre-empt: the cancellation is delivered inside the fiber, at the wait
        // point, to let the workflow compensate.
    }

    /**
     * Requested, and not yet delivered: the delivery is traced by {@see WorkflowCancellationDelivered}
     * — without that bound, every replay would raise the cancellation again, including inside
     * the compensation waits. Journals written before that event existed carry the delivery only
     * as an operation cancelled with the workflow_cancelled reason, which still counts.
     */
    public function isCancellationPending(ExecutionId $executionId): bool
    {
        $requested = false;
        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof WorkflowCancellationRequested) {
                $requested = true;
            }
            if ($event instanceof ExecutionCompleted
                || $event instanceof WorkflowExecutionFailed
                || $event instanceof WorkflowExecutionCancelled
            ) {
                $requested = false;
            }
            if ($event instanceof WorkflowCancellationDelivered
                || (($event instanceof ActivityCancelled || $event instanceof TimerCancelled)
                    && ActivityCancellationReason::WORKFLOW_CANCELLED === $event->reason())
            ) {
                return false;
            }
        }

        return $requested;
    }

    public function onCancellationDelivered(ExecutionId $executionId, array $cancelledOperationIds): void
    {
        // ActivityCancelled / TimerCancelled already carry the workflow_cancelled reason, which
        // rejects those awaits on replay. A condition has no such event: this one is what places
        // the delivery in the journal for it (#317).
        $this->eventStore->append(new WorkflowCancellationDelivered($executionId, $cancelledOperationIds));
    }

    public function onCancelled(ExecutionId $executionId, WorkflowCancelledFailure $failure): void
    {
        $source = null;
        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof WorkflowCancellationRequested) {
                $source = $event->sourceParentExecutionId();
            }
        }

        $this->eventStore->append(new WorkflowExecutionCancelled($executionId, $failure->reason, $source));
        $this->parentChildCoordinator?->onParentClosed($executionId, ParentClosureReason::Cancelled);

        throw new WorkflowCancelledException($executionId->toString(), $failure->reason);
    }

    public function onCompleted(ExecutionId $executionId, mixed $result): void
    {
        $this->eventStore->append(new ExecutionCompleted($executionId, $result));
        $this->parentChildCoordinator?->onParentClosed($executionId, ParentClosureReason::CompletedSuccessfully);
    }

    public function onSuspended(ExecutionId $executionId, Awaitable $pending): void
    {
        // Must go through the composites: an any(activity, timer) really is waiting on a deadline.
        $waitingOnTimer = AwaitableInspector::waitsOnTimer($pending);

        throw new WorkflowSuspendedException(
            \sprintf('Workflow %s suspended (fiber mode)', $executionId->toString()),
            0,
            null,
            $waitingOnTimer,
            $waitingOnTimer,
            WaitReason::describe($pending, $this->eventStore, $executionId),
        );
    }

    public function onContinuedAsNew(ExecutionId $executionId, ContinueAsNewRequested $request): void
    {
        // A replay reaches the same continuation: the next id is the one the first pass recorded,
        // and the journal already says so (#878). The first one, should an older bug have left two;
        // the second id may then stay linked to a parent, with no run behind it.
        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof WorkflowContinuedAsNew && null !== $event->newExecutionId()) {
                throw $request->withNextExecutionId($event->newExecutionId());
            }
        }

        // After the replay scan: a continuation journaled before this check still replays. The
        // options are refused here, not applied: no task queue to move to, no timer for the run bounds (#977).
        foreach ([
            'taskQueue' => null !== $request->options?->taskQueue,
            'timeouts->run' => null !== $request->options?->timeouts->run,
            'timeouts->task' => null !== $request->options?->timeouts->task,
        ] as $option => $given) {
            if ($given) {
                throw UnsupportedByBackendException::forMethod('journal', 'continueAsNew', \sprintf('ContinueAsNewOptions::$%s is applied by Temporal only; the journal backends (InMemory, Doctrine DBAL, Illuminate, Magento) would record it and ignore it. Remove it, or run on Temporal.', $option));
            }
        }

        $next = ExecutionId::generate();
        $request = $request->withNextExecutionId($next);
        $this->eventStore->append(new WorkflowContinuedAsNew(
            $executionId,
            $request->workflowType,
            $request->payload,
            null !== $request->options ? $request->options->toMetadata() : [],
            $next,
        ));

        throw $request;
    }

    public function onFailed(ExecutionId $executionId, \Throwable $failure): void
    {
        $this->eventStore->append(WorkflowFailureClassifier::classify($executionId, $failure));
        $this->parentChildCoordinator?->onParentClosed($executionId, ParentClosureReason::Failed);

        throw match (true) {
            $failure instanceof DurableCatastrophicActivityFailureException => new DurableWorkflowAlgorithmFailureException('Workflow did not handle catastrophic activity failure: ' . $failure->getMessage(), 0, $failure),
            $failure instanceof DurableActivityFailedException => new DurableWorkflowAlgorithmFailureException('Workflow did not handle activity failure: ' . $failure->getMessage(), 0, $failure),
            $failure instanceof ActivitySupersededException => new DurableWorkflowAlgorithmFailureException('Workflow did not handle superseded activity: ' . $failure->getMessage(), 0, $failure),
            $failure instanceof DeclaredActivityFailureInterface => new DurableWorkflowAlgorithmFailureException('Workflow did not handle declared activity failure: ' . $failure->getMessage(), 0, $failure),
            default => $failure,
        };
    }
}
