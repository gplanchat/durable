<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Exception\ChildWorkflowDeferredToMessenger;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\ChildWorkflowParentLinkStoreInterface;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Exécute un workflow enfant sur son propre `executionId` (journal distinct du parent).
 *
 * Mode **inline** (défaut) : {@see InMemoryWorkflowRunner} jusqu’à complétion.
 * Mode **async_messenger** : dispatch {@see Transport\WorkflowRunMessage} uniquement ;
 * le parent reprend via {@see Bundle\Handler\WorkflowRunHandler} qui append
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
     * Indique si le démarrage d’enfant passe par Messenger (pas d’exécution inline dans {@see runChild}).
     */
    public function defersChildStartToMessenger(): bool
    {
        return $this->asyncMessengerStart;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws ChildWorkflowDeferredToMessenger si {@see $asyncMessengerStart} : pas d’append ChildWorkflowCompleted ici
     */
    public function runChild(string $childExecutionId, string $workflowType, array $input, ?string $parentExecutionId = null): mixed
    {
        if ($this->asyncMessengerStart) {
            if (null === $parentExecutionId || '' === $parentExecutionId) {
                throw new \InvalidArgumentException('parentExecutionId is required for async Messenger child workflow start.');
            }
            $this->parentLinkStore->link($childExecutionId, $parentExecutionId);
            $this->workflowResumeDispatcher->dispatchNewWorkflowRun($childExecutionId, $workflowType, $input);
            throw new ChildWorkflowDeferredToMessenger();
        }

        $runner = new InMemoryWorkflowRunner(
            $this->eventStore,
            $this->runtime->getActivityTransport(),
            $this->activityExecutor,
            $this->maxActivityRetries,
        );
        $handler = $this->workflowRegistry->getHandler($workflowType, $input);

        return $runner->run($childExecutionId, $handler);
    }
}
