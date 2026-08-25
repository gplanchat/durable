<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\TimerAwaitable;
use Gplanchat\Durable\Event\ExecutionCompleted;
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
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\Failure\WorkflowFailureClassifier;
use Gplanchat\Durable\ParentClosureReason;
use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;
use Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;

/**
 * Issues de cycle de vie du backend in-memory : journal + clôture des enfants, et signalement
 * à l'appelant par exception (suspension, annulation, échec non géré).
 */
final readonly class EventStoreWorkflowLifecycle implements WorkflowLifecycleInterface
{
    public function __construct(
        private EventStoreInterface $eventStore,
        private ?ParentChildWorkflowCoordinatorInterface $parentChildCoordinator = null,
    ) {
    }

    /**
     * Une annulation demandée est honorée au point de reprise suivant : le run ne redémarre pas,
     * le journal reçoit sa contrepartie terminale et les enfants sont clôturés.
     */
    public function onBeforeRun(string $executionId): void
    {
        $pendingReason = null;
        $pendingSource = null;
        foreach ($this->eventStore->readStream($executionId) as $event) {
            if ($event instanceof WorkflowCancellationRequested) {
                $pendingReason = $event->reason();
                $pendingSource = $event->sourceParentExecutionId();
            }
            if ($event instanceof ExecutionCompleted
                || $event instanceof WorkflowExecutionFailed
                || $event instanceof WorkflowExecutionCancelled
            ) {
                $pendingReason = null;
                $pendingSource = null;
            }
        }

        if (null === $pendingReason) {
            return;
        }

        $this->eventStore->append(new WorkflowExecutionCancelled($executionId, $pendingReason, $pendingSource));
        $this->parentChildCoordinator?->onParentClosed($executionId, ParentClosureReason::Cancelled);

        throw new WorkflowCancelledException($executionId, $pendingReason);
    }

    public function onCompleted(string $executionId, mixed $result): void
    {
        $this->eventStore->append(new ExecutionCompleted($executionId, $result));
        $this->parentChildCoordinator?->onParentClosed($executionId, ParentClosureReason::CompletedSuccessfully);
    }

    public function onSuspended(string $executionId, Awaitable $pending): void
    {
        $waitingOnTimer = $pending instanceof TimerAwaitable;

        throw new WorkflowSuspendedException(
            \sprintf('Workflow %s suspended (fiber mode)', $executionId),
            0,
            null,
            $waitingOnTimer,
            $waitingOnTimer,
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
            $failure instanceof DurableCatastrophicActivityFailureException => new DurableWorkflowAlgorithmFailureException('Workflow did not handle catastrophic activity failure: '.$failure->getMessage(), 0, $failure),
            $failure instanceof DurableActivityFailedException => new DurableWorkflowAlgorithmFailureException('Workflow did not handle activity failure: '.$failure->getMessage(), 0, $failure),
            $failure instanceof ActivitySupersededException => new DurableWorkflowAlgorithmFailureException('Workflow did not handle superseded activity: '.$failure->getMessage(), 0, $failure),
            $failure instanceof DeclaredActivityFailureInterface => new DurableWorkflowAlgorithmFailureException('Workflow did not handle declared activity failure: '.$failure->getMessage(), 0, $failure),
            default => $failure,
        };
    }
}
