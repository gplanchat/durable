<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\TimerAwaitable;
use Gplanchat\Durable\Activity\ActivityContractResolver;use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;
use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;
use Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface;
use Gplanchat\Durable\Failure\WorkflowFailureClassifier;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Uuid\UuidGeneratorInterface;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

final class ExecutionEngine
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ExecutionRuntime $runtime,
        private readonly ?ChildWorkflowRunnerInterface $childWorkflowRunner = null,
        private readonly ?ParentChildWorkflowCoordinatorInterface $parentChildCoordinator = null,
        private readonly ?ActivityContractResolver $activityContractResolver = null,
        private readonly ?WorkflowDefinitionLoader $workflowDefinitionLoader = null,
        private readonly ?WorkflowExecutionObserverInterface $workflowExecutionObserver = null,
        private readonly ?UuidGeneratorInterface $uuidGenerator = null,
    ) {
    }

    /**
     * @param array<string, mixed> $executionStartedPayloadExtras Fusionnés dans le payload {@see ExecutionStarted} (ex. bootstrap interpréteur Temporal).
     */
    public function start(string $executionId, callable $handler, ?string $workflowType = null, array $executionStartedPayloadExtras = []): mixed
    {
        $this->workflowExecutionObserver?->onWorkflowRun($executionId, $workflowType ?? '(unknown)', false);

        $context = new ExecutionContext(
            $executionId,
            new EventStoreHistorySource($this->eventStore, $executionId),
            new EventStoreCommandBuffer($this->eventStore, $this->runtime->getActivityTransport(), $executionId),
            $this->childWorkflowRunner,
            $this->uuidGenerator,
        );

        if (0 === $this->eventStore->countEventsInStream($executionId)) {
            $startedPayload = [];
            if (null !== $workflowType && '' !== $workflowType) {
                $startedPayload['workflowType'] = $workflowType;
            }
            if ($executionStartedPayloadExtras !== []) {
                $startedPayload = array_merge($startedPayload, $executionStartedPayloadExtras);
            }
            $this->eventStore->append(new ExecutionStarted($executionId, $startedPayload));
        }

        return $this->runHandler($context, $this->createEnvironment($context), $handler);
    }

    /**
     * Reprend une exécution suspendue. N'ajoute pas ExecutionStarted.
     * Utilisé après WorkflowSuspendedException lorsque les activités ont été exécutées.
     */
    public function resume(string $executionId, callable $handler, ?string $workflowType = null): mixed
    {
        $this->workflowExecutionObserver?->onWorkflowRun($executionId, $workflowType ?? '(unknown)', true);

        $context = new ExecutionContext(
            $executionId,
            new EventStoreHistorySource($this->eventStore, $executionId),
            new EventStoreCommandBuffer($this->eventStore, $this->runtime->getActivityTransport(), $executionId),
            $this->childWorkflowRunner,
            $this->uuidGenerator,
        );

        return $this->runHandler($context, $this->createEnvironment($context), $handler);
    }

    private function createEnvironment(ExecutionContext $context): WorkflowEnvironment
    {
        return new WorkflowEnvironment(
            $context,
            $this->runtime,
            $this->activityContractResolver,
            $this->workflowDefinitionLoader,
        );
    }

    private function runHandler(ExecutionContext $context, WorkflowEnvironment $environment, callable $handler): mixed
    {
        $this->honorPendingCancellation($context);

        $fiber = new \Fiber(static fn () => $handler($environment));

        try {
            $suspended = $fiber->start();
        } catch (DurableCatastrophicActivityFailureException $e) {
            $this->failFromFiber($context, $e);
            throw new DurableWorkflowAlgorithmFailureException('Workflow did not handle catastrophic activity failure: '.$e->getMessage(), 0, $e);
        } catch (DurableActivityFailedException $e) {
            $this->failFromFiber($context, $e);
            throw new DurableWorkflowAlgorithmFailureException('Workflow did not handle activity failure: '.$e->getMessage(), 0, $e);
        } catch (ActivitySupersededException $e) {
            $this->failFromFiber($context, $e);
            throw new DurableWorkflowAlgorithmFailureException('Workflow did not handle superseded activity: '.$e->getMessage(), 0, $e);
        } catch (DeclaredActivityFailureInterface $e) {
            $this->failFromFiber($context, $e);
            throw new DurableWorkflowAlgorithmFailureException('Workflow did not handle declared activity failure: '.$e->getMessage(), 0, $e);
        } catch (ContinueAsNewRequested $e) {
            $continuation = null !== $e->options ? $e->options->toMetadata() : [];
            $this->eventStore->append(new WorkflowContinuedAsNew($context->executionId(), $e->workflowType, $e->payload, $continuation));
            throw $e;
        } catch (\Throwable $e) {
            $this->failFromFiber($context, $e);
            throw $e;
        }

        // Drive the fiber until it terminates or a new command is encountered
        while ($fiber->isSuspended()) {
            if (!($suspended instanceof Awaitable)) {
                break;
            }

            if ($suspended->isSettled()) {
                // Replay path: awaitable was resolved before await() was called; resume immediately
                try {
                    $suspended = $fiber->resume();
                } catch (\Throwable $e) {
                    $this->handleFiberThrowable($context, $e);
                    throw $e;
                }
            } else {
                // New command: buffered in WorkflowCommandBufferInterface; stop the fiber
                $shouldDispatchResume = $suspended instanceof TimerAwaitable;
                throw new WorkflowSuspendedException(
                    \sprintf('Workflow %s suspended (fiber mode)', $context->executionId()),
                    0,
                    null,
                    $shouldDispatchResume,
                    $suspended instanceof TimerAwaitable,
                );
            }
        }

        if ($fiber->isTerminated()) {
            $result = $fiber->getReturn();
            $this->eventStore->append(new ExecutionCompleted($context->executionId(), $result));
            $this->parentChildCoordinator?->onParentClosed($context->executionId(), ParentClosureReason::CompletedSuccessfully);

            return $result;
        }

        return null;
    }

    private function handleFiberThrowable(ExecutionContext $context, \Throwable $e): void
    {
        if ($e instanceof ContinueAsNewRequested) {
            $continuation = null !== $e->options ? $e->options->toMetadata() : [];
            $this->eventStore->append(new WorkflowContinuedAsNew($context->executionId(), $e->workflowType, $e->payload, $continuation));

            return;
        }

        $this->failFromFiber($context, $e);
    }

    /**
     * Une annulation demandée est honorée au point de reprise suivant : le run ne redémarre pas,
     * le journal reçoit sa contrepartie terminale et les enfants sont clôturés.
     *
     * @throws WorkflowCancelledException
     */
    private function honorPendingCancellation(ExecutionContext $context): void
    {
        $pendingReason = null;
        $pendingSource = null;
        foreach ($this->eventStore->readStream($context->executionId()) as $event) {
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

        $this->eventStore->append(new WorkflowExecutionCancelled($context->executionId(), $pendingReason, $pendingSource));
        $this->parentChildCoordinator?->onParentClosed($context->executionId(), ParentClosureReason::Cancelled);

        throw new WorkflowCancelledException($context->executionId(), $pendingReason);
    }

    private function failFromFiber(ExecutionContext $context, \Throwable $e): void
    {
        $this->eventStore->append(WorkflowFailureClassifier::classify($context->executionId(), $e));
        $this->notifyParentFailed($context);
    }

    private function notifyParentFailed(ExecutionContext $context): void
    {
        $this->parentChildCoordinator?->onParentClosed($context->executionId(), ParentClosureReason::Failed);
    }

    public function getRuntime(): ExecutionRuntime
    {
        return $this->runtime;
    }
}
