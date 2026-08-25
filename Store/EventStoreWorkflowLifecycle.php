<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\AwaitableInspector;
use Gplanchat\Durable\ActivityCancellationReason;
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

    public function onBeforeRun(string $executionId): void
    {
        // Rien à pré-empter : l'annulation est livrée dans le fiber, au point d'attente, pour
        // laisser le workflow compenser.
    }

    /**
     * Demandée, et pas encore livrée : la livraison se trace par l'annulation d'une opération
     * avec la raison workflow_cancelled — sans cette borne, chaque replay relèverait de nouveau
     * l'annulation, y compris dans les attentes de compensation.
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
        // Doit traverser les composites : un any(activity, timer) attend bien une échéance.
        $waitingOnTimer = AwaitableInspector::waitsOnTimer($pending);

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
