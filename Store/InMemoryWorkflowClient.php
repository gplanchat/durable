<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowStartOptionsRefused;
use Gplanchat\Durable\Exception\WorkflowTerminatedException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Handler\LinkedChildFinalizer;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\ParentClosureReason;
use Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\WorkflowStartOptions;

/**
 * The in-memory implementation of {@see WorkflowClientPort}, start, cancel and terminate. A start inserts
 * the metadata row, fails on a second start of the id, then dispatches the run (#603).
 *
 * The other operations throw {@see UnsupportedByBackendException} until tasks 2.1 to 2.3. A start
 * with options throws {@see WorkflowStartOptionsRefused} until task 2.6.
 */
final class InMemoryWorkflowClient implements WorkflowClientPort
{
    public function __construct(
        private readonly WorkflowMetadataStore $metadata,
        private readonly WorkflowResumeDispatcher $dispatcher,
        private readonly WorkflowRunCatalogInterface $catalog,
        private readonly EventStoreInterface $events,
        private readonly LinkedChildFinalizer $linkedChildren,
        private readonly ?ParentChildWorkflowCoordinatorInterface $coordinator = null,
    ) {}

    public function start(ExecutionId $executionId, string $workflowType, array $input = [], ?WorkflowStartOptions $options = null): ExecutionId
    {
        $refused = null === $options ? [] : self::refusedOptions($options);
        if ([] !== $refused) {
            throw new WorkflowStartOptionsRefused('in-memory', $refused);
        }
        if (!$this->metadata->insertIfAbsent($executionId, $workflowType, $input)) {
            throw new WorkflowExecutionAlreadyStarted($executionId->toString());
        }
        $this->dispatcher->dispatchNewWorkflowRun($executionId, $workflowType, $input);

        return $executionId;
    }

    public function signal(ExecutionId $executionId, string $name, array $arguments = [], ?string $requestId = null): void
    {
        $this->assertNotEnded($executionId);

        throw $this->notYet('signal');
    }

    public function query(ExecutionId $executionId, string $name, array $arguments = []): mixed
    {
        $this->assertKnown($executionId);

        throw $this->notYet('query');
    }

    public function update(ExecutionId $executionId, string $name, array $arguments = [], ?string $updateId = null): mixed
    {
        $this->assertNotEnded($executionId);

        throw $this->notYet('update');
    }

    public function result(ExecutionId $executionId, ?Duration $timeout = null): mixed
    {
        $this->assertKnown($executionId);

        throw $this->notYet('result');
    }

    public function cancel(ExecutionId $executionId, ?string $requestId = null): void
    {
        $this->assertOnTheLiveRun($executionId, 'cancel');
        // Idempotent, as Temporal is expected to be (unverified, probe 0.2): a request already
        // journalled for this run is not repeated.
        foreach ($this->events->readStream($executionId) as $event) {
            if ($event instanceof WorkflowCancellationRequested) {
                return;
            }
        }
        $this->events->append(new WorkflowCancellationRequested($executionId, 'client_request_cancel'));
        WorkflowTaskJournal::schedule($this->events, $this->dispatcher, $executionId);
        $this->dispatcher->dispatchResume($executionId);
    }

    public function terminate(ExecutionId $executionId, string $reason = ''): void
    {
        // A retry after a dispatch failed: the failure is journalled, the tail is not done.
        $half = $this->halfTerminated($executionId);
        if (null !== $half) {
            $this->finishTermination($executionId, $half);

            return;
        }
        $this->assertOnTheLiveRun($executionId, 'terminate');
        // A pass that was running no longer writes (DUR053): it cannot complete a terminated run.
        $journal = PassEventStore::open($this->events, $executionId);
        // A pass may have ended the run between the check and the claim.
        $this->assertOnTheLiveRun($executionId, 'terminate');
        $journal->append(WorkflowExecutionFailed::terminated($executionId, $reason));
        $this->finishTermination($executionId, $reason);
    }

    /**
     * What every ending runs, in the worker's order and idempotent: the run's own children, the
     * parent that waits for it, and the row marked completed last, so that a retry repairs an
     * ending that stopped half way.
     */
    private function finishTermination(ExecutionId $executionId, string $reason): void
    {
        $this->coordinator?->onParentClosed($executionId, ParentClosureReason::Failed);
        $this->linkedChildren->finalize($executionId, null, new WorkflowTerminatedException($executionId->toString(), $reason));
        // A resume already queued then takes the handler's "completed" path and runs no workflow code.
        $this->metadata->markCompleted($executionId);
    }

    /**
     * The reason of a termination whose row is not marked completed yet; null otherwise.
     */
    private function halfTerminated(ExecutionId $executionId): ?string
    {
        $last = null;
        foreach ($this->events->readStream($executionId) as $event) {
            if ($event instanceof WorkflowExecutionFailed) {
                $last = $event;
            }
        }
        if (WorkflowExecutionFailed::KIND_TERMINATED !== $last?->kind() || true === ($this->metadata->get($executionId)['completed'] ?? false)) {
            return null;
        }

        return $last->failureMessage();
    }

    /**
     * Until task 2.6 no option takes effect on this backend: each one that differs from the
     * defaults is refused.
     *
     * @return list<string>
     */
    private static function refusedOptions(WorkflowStartOptions $options): array
    {
        $defaults = WorkflowStartOptions::defaults();

        // Every public property: one added to WorkflowStartOptions later is refused, not accepted.
        return array_keys(array_filter(
            get_object_vars($options),
            static fn(mixed $value, string $name): bool => $value != $defaults->{$name},
            \ARRAY_FILTER_USE_BOTH,
        ));
    }

    private function assertKnown(ExecutionId $executionId): WorkflowRunStatus
    {
        return ($this->catalog->findRun($executionId) ?? throw new WorkflowExecutionNotFound($executionId->toString()))->status;
    }

    /**
     * A run that continued as new is not ended: its chain goes on (design, task 1.7).
     */
    private function assertNotEnded(ExecutionId $executionId): void
    {
        if (!\in_array($this->assertKnown($executionId), [WorkflowRunStatus::Running, WorkflowRunStatus::ContinuedAsNew], true)) {
            throw new WorkflowExecutionAlreadyEnded($executionId->toString());
        }
    }

    /**
     * Cancel and terminate act on the live run of a chain. Until task 1.7 follows it, a run that
     * continued as new is refused: the call would land on the dead run.
     */
    private function assertOnTheLiveRun(ExecutionId $executionId, string $method): void
    {
        $this->assertNotEnded($executionId);
        if (WorkflowRunStatus::ContinuedAsNew === $this->assertKnown($executionId)) {
            throw UnsupportedByBackendException::forMethod('in-memory', $method, 'the execution continued as new and the chain is not followed yet, see the workflow-repository change');
        }
    }

    private function notYet(string $method): UnsupportedByBackendException
    {
        return UnsupportedByBackendException::forMethod('in-memory', $method, 'not implemented yet, see the workflow-repository change');
    }
}
