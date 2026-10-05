<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Exception\ChildWorkflowStartDeferred;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\Store\WorkflowTaskJournal;
use Psr\Clock\ClockInterface;

/**
 * Runs a child workflow on its own `executionId` (a log distinct from the parent's).
 *
 * **inline** mode (the default): {@see InMemoryWorkflowRunner} until completion.
 * **async_messenger** mode: dispatches the child's run through {@see Port\WorkflowResumeDispatcher} only;
 * the parent resumes through {@see Handler\ResumeWorkflowHandler}, which appends
 * {@see Event\ChildWorkflowCompleted} / {@see Event\ChildWorkflowFailed}.
 */
final readonly class ChildWorkflowRunner implements ChildWorkflowRunnerInterface
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
        /**
         * The clock the transport stamps its due times with, when it is not the runtime's: the
         * in-memory runner's runtime runs on a virtual clock, its queue does not.
         */
        private readonly ?ClockInterface $queueClock = null,
        /**
         * Async mode: the row of a child id the reuse policy let through is cleared before the
         * start, since the dispatcher keeps an existing row as it is (#918).
         */
        private readonly ?WorkflowMetadataStore $metadataStore = null,
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
    public function runChild(ExecutionId $childExecutionId, string $workflowType, array $input, ?ExecutionId $parentExecutionId = null): mixed
    {
        if ($this->asyncMessengerStart) {
            if (null === $parentExecutionId) {
                throw new \InvalidArgumentException('parentExecutionId is required for async Messenger child workflow start.');
            }
            $this->parentLinkStore->link($childExecutionId, $parentExecutionId);
            // A start reaches here once per scheduled child, after assertChildWorkflowIdAllowed():
            // a completed row is that of a child whose id the policy lets this parent reuse
            // (AllowDuplicateFailedOnly by default). Left completed, the new start would never run.
            // Only a completed row goes: a running child keeps its row, so no race reopens it.
            if (true === ($this->metadataStore?->get($childExecutionId)['completed'] ?? false)) {
                $this->metadataStore->delete($childExecutionId);
            }
            WorkflowTaskJournal::schedule($this->eventStore, $this->workflowResumeDispatcher, $childExecutionId);
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
            clock: $this->queueClock,
            // The child's time starts at its parent's (virtual) now, not at the real now (#652).
            virtualTimeStartsAt: $this->runtime->clock(),
        );
        $handler = $this->workflowRegistry->getHandler($workflowType, $input);

        return $runner->run($childExecutionId, $handler, $workflowType);
    }
}
