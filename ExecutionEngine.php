<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;
use Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

final class ExecutionEngine
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ExecutionRuntime $runtime,
        private readonly ?ChildWorkflowRunner $childWorkflowRunner = null,
        private readonly ?ParentChildWorkflowCoordinatorInterface $parentChildCoordinator = null,
        private readonly ?ActivityContractResolver $activityContractResolver = null,
        private readonly ?WorkflowDefinitionLoader $workflowDefinitionLoader = null,
        private readonly ?WorkflowExecutionObserverInterface $workflowExecutionObserver = null,
    ) {
    }

    public function start(string $executionId, callable $handler, ?string $workflowType = null): mixed
    {
        $this->workflowExecutionObserver?->onWorkflowRun($executionId, $workflowType ?? '(unknown)', false);

        $context = new ExecutionContext(
            $executionId,
            $this->eventStore,
            $this->runtime->getActivityTransport(),
            $this->childWorkflowRunner,
        );

        $this->eventStore->append(new ExecutionStarted($executionId));

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
            $this->eventStore,
            $this->runtime->getActivityTransport(),
            $this->childWorkflowRunner,
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
        try {
            $result = $handler($environment);
        } catch (DurableCatastrophicActivityFailureException $e) {
            $this->eventStore->append(WorkflowExecutionFailed::unhandledCatastrophicActivity(
                $context->executionId(),
                $e,
            ));
            $this->notifyParentFailed($context);
            throw new DurableWorkflowAlgorithmFailureException('Workflow did not handle catastrophic activity failure: '.$e->getMessage(), 0, $e);
        } catch (DurableActivityFailedException $e) {
            $this->eventStore->append(WorkflowExecutionFailed::unhandledActivityFailure(
                $context->executionId(),
                $e->activityId(),
                $e->activityName(),
                $e,
            ));
            $this->notifyParentFailed($context);
            throw new DurableWorkflowAlgorithmFailureException('Workflow did not handle activity failure: '.$e->getMessage(), 0, $e);
        } catch (ActivitySupersededException $e) {
            $this->eventStore->append(WorkflowExecutionFailed::unhandledActivitySuperseded(
                $context->executionId(),
                $e,
            ));
            $this->notifyParentFailed($context);
            throw new DurableWorkflowAlgorithmFailureException('Workflow did not handle superseded activity: '.$e->getMessage(), 0, $e);
        } catch (DeclaredActivityFailureInterface $e) {
            $this->eventStore->append(WorkflowExecutionFailed::unhandledDeclaredActivityFailure(
                $context->executionId(),
                $e,
            ));
            $this->notifyParentFailed($context);
            throw new DurableWorkflowAlgorithmFailureException('Workflow did not handle declared activity failure: '.$e->getMessage(), 0, $e);
        } catch (WorkflowSuspendedException $e) {
            throw $e;
        } catch (ContinueAsNewRequested $e) {
            $continuation = null !== $e->options ? $e->options->toMetadata() : [];
            $this->eventStore->append(new WorkflowContinuedAsNew(
                $context->executionId(),
                $e->workflowType,
                $e->payload,
                $continuation,
            ));
            throw $e;
        } catch (\Throwable $e) {
            $this->eventStore->append(WorkflowExecutionFailed::workflowHandlerFailure(
                $context->executionId(),
                $e,
            ));
            $this->notifyParentFailed($context);
            throw $e;
        }

        $this->eventStore->append(new ExecutionCompleted($context->executionId(), $result));
        $this->parentChildCoordinator?->onParentClosed($context->executionId(), ParentClosureReason::CompletedSuccessfully);

        return $result;
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
