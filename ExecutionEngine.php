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
use Gplanchat\Durable\Store\PassEventStore;
use Gplanchat\Durable\Uuid\UuidGeneratorInterface;
use Gplanchat\Durable\Worker\WorkflowFiberDriver;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

final readonly class ExecutionEngine
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
     * @param array<string, mixed>                          $executionStartedPayloadExtras Merged into the {@see ExecutionStarted} payload (e.g. Temporal interpreter bootstrap).
     * @param list<\Gplanchat\Durable\Workflow\PendingUpdate> $pendingUpdates
     */
    public function start(string $executionId, callable $handler, ?string $workflowType = null, array $executionStartedPayloadExtras = [], array $pendingUpdates = []): mixed
    {
        // The message and the runner hand a string; it is converted here, once.
        $id = ExecutionId::fromString($executionId);
        $this->workflowExecutionObserver?->onWorkflowRun($id, $workflowType ?? '(unknown)', false);

        // Claimed before the history is read: a pass started after this one supersedes it (DUR053).
        $journal = PassEventStore::open($this->eventStore, $executionId);
        $history = new EventStoreHistorySource($journal, $executionId);
        $context = new ExecutionContext(
            $id,
            $history,
            new EventStoreCommandBuffer(
                $journal,
                $this->runtime->getActivityTransport(),
                $id,
                $this->runtime->clock(),
                $history,
            ),
            $this->childWorkflowRunner,
            $this->uuidGenerator,
            $pendingUpdates,
        );

        if (0 === $journal->countEventsInStream($id)) {
            $startedPayload = [];
            if (null !== $workflowType && '' !== $workflowType) {
                $startedPayload['workflowType'] = $workflowType;
            }
            if ($executionStartedPayloadExtras !== []) {
                $startedPayload = array_merge($startedPayload, $executionStartedPayloadExtras);
            }
            $journal->append(new ExecutionStarted($id, $startedPayload));
        }

        return $this->runHandler($context, $this->createEnvironment($context), $handler, $journal);
    }

    /**
     * Resumes a suspended execution. Does not append ExecutionStarted.
     * Used after WorkflowSuspendedException once the activities have run.
     *
     * @param list<\Gplanchat\Durable\Workflow\PendingUpdate> $pendingUpdates
     */
    public function resume(string $executionId, callable $handler, ?string $workflowType = null, array $pendingUpdates = []): mixed
    {
        // The message and the runner hand a string; it is converted here, once.
        $id = ExecutionId::fromString($executionId);
        $this->workflowExecutionObserver?->onWorkflowRun($id, $workflowType ?? '(unknown)', true);

        // Claimed before the history is read: a pass started after this one supersedes it (DUR053).
        $journal = PassEventStore::open($this->eventStore, $executionId);
        $history = new EventStoreHistorySource($journal, $executionId);
        $context = new ExecutionContext(
            $id,
            $history,
            new EventStoreCommandBuffer(
                $journal,
                $this->runtime->getActivityTransport(),
                $id,
                $this->runtime->clock(),
                $history,
            ),
            $this->childWorkflowRunner,
            $this->uuidGenerator,
            $pendingUpdates,
        );

        return $this->runHandler($context, $this->createEnvironment($context), $handler, $journal);
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

    private function runHandler(ExecutionContext $context, WorkflowEnvironment $environment, callable $handler, EventStoreInterface $journal): mixed
    {
        $driver = new WorkflowFiberDriver(new EventStoreWorkflowLifecycle(
            $journal,
            $this->parentChildCoordinator,
        ));

        return $driver->run($context->executionId()->toString(), $context, $environment, $handler);
    }

    public function getRuntime(): ExecutionRuntime
    {
        return $this->runtime;
    }
}
