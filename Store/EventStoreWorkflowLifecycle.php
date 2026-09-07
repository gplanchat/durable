<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\AwaitableInspector;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\Failure\WorkflowFailureClassifier;
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

    public function onBeforeRun(string $executionId): void
    {
        // Nothing to pre-empt: the cancellation is delivered inside the fiber, at the wait
        // point, to let the workflow compensate.
    }

    /**
     * Requested, and not yet delivered: the delivery is traced by the cancellation of an
     * operation with the workflow_cancelled reason — without that bound, every replay would raise
     * the cancellation again, including inside the compensation waits.
     */
    public function isCancellationPending(string $executionId): bool
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
            if (($event instanceof ActivityCancelled || $event instanceof TimerCancelled)
                && ActivityCancellationReason::WORKFLOW_CANCELLED === $event->reason()
            ) {
                return false;
            }
        }

        return $requested;
    }

    public function onCancellationDelivered(string $executionId, array $cancelledOperationIds): void
    {
        // Nothing to add: ActivityCancelled / TimerCancelled already carry the
        // workflow_cancelled reason, which serves both as the delivery trace and as the source
        // of the rejection on replay.
    }

    public function onCancelled(string $executionId, WorkflowCancelledFailure $failure): void
    {
        $source = null;
        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof WorkflowCancellationRequested) {
                $source = $event->sourceParentExecutionId();
            }
        }

        $this->eventStore->append(new WorkflowExecutionCancelled($executionId, $failure->reason, $source));
        $this->parentChildCoordinator?->onParentClosed($executionId, ParentClosureReason::Cancelled);

        throw new WorkflowCancelledException($executionId, $failure->reason);
    }

    public function onCompleted(string $executionId, mixed $result): void
    {
        $this->eventStore->append(new ExecutionCompleted($executionId, $result));
        $this->parentChildCoordinator?->onParentClosed($executionId, ParentClosureReason::CompletedSuccessfully);
    }

    public function onSuspended(string $executionId, Awaitable $pending): void
    {
        // Must go through the composites: an any(activity, timer) really is waiting on a deadline.
        $waitingOnTimer = AwaitableInspector::waitsOnTimer($pending);

        throw new WorkflowSuspendedException(
            \sprintf('Workflow %s suspended (fiber mode)', $executionId),
            0,
            null,
            $waitingOnTimer,
            $waitingOnTimer,
            AwaitableInspector::describeCondition($pending),
        );
    }

    public function onContinuedAsNew(string $executionId, ContinueAsNewRequested $request): void
    {
        $this->eventStore->append(new WorkflowContinuedAsNew(
            $executionId,
            $request->workflowType,
            $request->payload,
            null !== $request->options ? $request->options->toMetadata() : [],
        ));

        throw $request;
    }

    public function onFailed(string $executionId, \Throwable $failure): void
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
