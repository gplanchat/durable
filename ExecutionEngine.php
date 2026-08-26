<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Activity\ActivityContractResolver;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;
use Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\EventStoreWorkflowLifecycle;
use Gplanchat\Durable\Uuid\UuidGeneratorInterface;
use Gplanchat\Durable\Worker\WorkflowFiberDriver;
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
    ) {}

    /**
     * @param array<string, mixed> $executionStartedPayloadExtras Fusionnés dans le payload {@see ExecutionStarted} (ex. bootstrap interpréteur Temporal).
     */
    public function start(string $executionId, callable $handler, ?string $workflowType = null, array $executionStartedPayloadExtras = []): mixed
    {
        $this->workflowExecutionObserver?->onWorkflowRun($executionId, $workflowType ?? '(unknown)', false);

        $context = new ExecutionContext(
            $executionId,
            new EventStoreHistorySource($this->eventStore, $executionId),
            new EventStoreCommandBuffer(
                $this->eventStore,
                $this->runtime->getActivityTransport(),
                $executionId,
                $this->runtime->nowSeconds(...),
            ),
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
            new EventStoreCommandBuffer(
                $this->eventStore,
                $this->runtime->getActivityTransport(),
                $executionId,
                $this->runtime->nowSeconds(...),
            ),
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
        $driver = new WorkflowFiberDriver(new EventStoreWorkflowLifecycle(
            $this->eventStore,
            $this->parentChildCoordinator,
        ));

        return $driver->run($context->executionId(), $context, $environment, $handler);
    }

    public function getRuntime(): ExecutionRuntime
    {
        return $this->runtime;
    }
}
