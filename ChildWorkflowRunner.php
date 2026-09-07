<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Exception\ChildWorkflowStartDeferred;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Runs a child workflow on its own `executionId` (a log distinct from the parent's).
 *
 * **inline** mode (the default): {@see InMemoryWorkflowRunner} until completion.
 * **async_messenger** mode: dispatches {@see Transport\WorkflowRunMessage} only;
 * the parent resumes through {@see Bundle\Handler\WorkflowRunHandler}, which appends
 * {@see Event\ChildWorkflowCompleted} / {@see Event\ChildWorkflowFailed}.
 */
final class ChildWorkflowRunner implements ChildWorkflowRunnerInterface
{
    private readonly bool $asyncMessengerStart;

    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ExecutionRuntime $runtime,
        private readonly WorkflowRegistry $workflowRegistry,
        private readonly ActivityExecutor $activityExecutor,
        private readonly int $maxActivityRetries = 0,
        bool $asyncMessengerStart = false,
        private readonly ?WorkflowResumeDispatcher $workflowResumeDispatcher = null,
        private readonly ?ChildWorkflowParentLinkStoreInterface $parentLinkStore = null,
    ) {
        $this->asyncMessengerStart = $asyncMessengerStart;
        if ($this->asyncMessengerStart && (null === $this->workflowResumeDispatcher || null === $this->parentLinkStore)) {
            throw new \InvalidArgumentException('Async child workflow requires WorkflowResumeDispatcher and ChildWorkflowParentLinkStoreInterface.');
        }
    }

    /**
     * Whether the child start goes through Messenger (no inline execution in {@see runChild}).
     */
    public function defersChildStart(): bool
    {
        return $this->asyncMessengerStart;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ChildWorkflowStartDeferred when {@see $asyncMessengerStart}: no ChildWorkflowCompleted appended here
     */
    public function runChild(string $childExecutionId, string $workflowType, array $input, ?string $parentExecutionId = null): mixed
    {
        if ($this->asyncMessengerStart) {
            if (null === $parentExecutionId || '' === $parentExecutionId) {
                throw new \InvalidArgumentException('parentExecutionId is required for async Messenger child workflow start.');
            }
            $this->parentLinkStore->link($childExecutionId, $parentExecutionId);
            $this->workflowResumeDispatcher->dispatchNewWorkflowRun($childExecutionId, $workflowType, $input);

            throw new ChildWorkflowStartDeferred();
        }

        // The registry is passed along so the child can itself start grandchildren.
        $runner = new InMemoryWorkflowRunner(
            $this->eventStore,
            $this->runtime->getActivityTransport(),
            $this->activityExecutor,
            $this->maxActivityRetries,
            $this->workflowRegistry,
        );
        $handler = $this->workflowRegistry->getHandler($workflowType, $input);

        return $runner->run($childExecutionId, $handler);
    }
}
