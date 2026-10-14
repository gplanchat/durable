<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Exception\DurableUpdateFailedException;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\Exception\WorkflowContinuedWithoutSuccessor;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowResultTimeout;
use Gplanchat\Durable\Exception\WorkflowStartOptionsRefused;
use Gplanchat\Durable\Exception\WorkflowSuccessorNotStarted;
use Gplanchat\Durable\Exception\WorkflowTerminatedException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\WorkflowFailureRebuilder;
use Gplanchat\Durable\Handler\LinkedChildFinalizer;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\ParentClosureReason;
use Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Query\ReadOnlyReplay;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\WorkflowStartOptions;

/**
 * The in-memory implementation of {@see WorkflowClientPort}, start, signal, update, cancel and terminate. A start inserts
 * the metadata row, fails on a second start of the id, then dispatches the run (#603).
 *
 * Result reads the journal once (task 2.3): nothing else runs in this process during the call, so an
 * ending can come only from an earlier append. An execution still open throws
 * {@see WorkflowResultTimeout} at once, with {@see Duration::infinity()} when no bound was given. A
 * run that continued as new gives the result of the last run of its chain, and a signal, an update,
 * a cancel and a terminate reach the run that is current. A chain whose next run never started
 * throws {@see WorkflowSuccessorNotStarted}, and one that names none throws
 * {@see WorkflowContinuedWithoutSuccessor}. The journal holds no run timeout until task 2.6, so no
 * ending maps to a timed out execution. The query replays the journal read-only
 * ({@see ReadOnlyReplay}). A start with options throws {@see WorkflowStartOptionsRefused} until task 2.6.
 */
final class InMemoryWorkflowClient implements WorkflowClientPort
{
    public function __construct(
        private readonly WorkflowMetadataStore $metadata,
        private readonly WorkflowResumeDispatcher $dispatcher,
        private readonly WorkflowRunCatalogInterface $catalog,
        private readonly EventStoreInterface $events,
        private readonly ReadOnlyReplay $queries,
        private readonly LinkedChildFinalizer $linkedChildren,
        private readonly ?ParentChildWorkflowCoordinatorInterface $coordinator = null,
        /** The name the refusals give for this backend: a database backend that reuses this client says so. */
        private readonly string $backend = 'in-memory',
    ) {}

    public function start(ExecutionId $executionId, string $workflowType, array $input = [], ?WorkflowStartOptions $options = null): ExecutionId
    {
        $refused = null === $options ? [] : self::refusedOptions($options);
        if ([] !== $refused) {
            throw new WorkflowStartOptionsRefused($this->backend, $refused);
        }
        if (!$this->metadata->insertIfAbsent($executionId, $workflowType, $input)) {
            throw new WorkflowExecutionAlreadyStarted($executionId->toString());
        }
        $this->dispatcher->dispatchNewWorkflowRun($executionId, $workflowType, $input);

        return $executionId;
    }

    /**
     * @throws WorkflowContinuedWithoutSuccessor
     * @throws WorkflowSuccessorNotStarted
     */
    public function signal(ExecutionId $executionId, string $name, array $arguments = [], ?string $requestId = null): void
    {
        $executionId = $this->currentOpenRun($executionId);
        // The order of DUR052, as the Symfony handler keeps it: announce, append, schedule, resume.
        $signal = AwaitedFact::signal($requestId ??= bin2hex(random_bytes(16)));
        if (!$signal->isJournalledIn($this->events, $executionId)) {
            $this->dispatcher->dispatchResumeAwaiting($executionId, $signal);
            $this->events->append(new WorkflowSignalReceived($executionId, $name, $arguments, $requestId));
        }
        WorkflowTaskJournal::schedule($this->events, $this->dispatcher, $executionId);
        $this->dispatcher->dispatchResume($executionId);
    }

    public function query(ExecutionId $executionId, string $name, array $arguments = []): mixed
    {
        $this->assertKnown($executionId);

        return $this->queries->query($executionId, $name, $arguments);
    }

    /**
     * A pass applies a pending update only inside a condition await. An update the workflow does not
     * declare, or one sent while it waits on a timer or an activity, is applied by no pass: the
     * caller receives a {@see DurableUpdateFailedException} whose reason is
     * {@see UpdateFailureReason::NotHandled}, and the update is not queued for later.
     *
     * @throws WorkflowContinuedWithoutSuccessor
     * @throws WorkflowSuccessorNotStarted
     * @throws DurableUpdateFailedException
     */
    public function update(ExecutionId $executionId, string $name, array $arguments = [], ?string $updateId = null): mixed
    {
        $executionId = $this->currentOpenRun($executionId);
        $updateId ??= bin2hex(random_bytes(16));
        // The same id again is the same update: the first outcome answers, nothing runs twice.
        $handled = $this->handledUpdate($executionId, $updateId);
        if (null === $handled) {
            WorkflowTaskJournal::schedule($this->events, $this->dispatcher, $executionId);
            $this->dispatcher->dispatchResume($executionId, [['name' => $name, 'arguments' => $arguments, 'updateId' => $updateId]]);
            $handled = $this->handledUpdate($executionId, $updateId) ?? throw DurableUpdateFailedException::notHandled($name);
        }
        if (null !== $handled->failure()) {
            throw new DurableUpdateFailedException($name, $handled->failure()->message);
        }

        return $handled->result();
    }

    private function handledUpdate(ExecutionId $executionId, string $updateId): ?WorkflowUpdateHandled
    {
        foreach ($this->events->readStream($executionId) as $event) {
            if ($event instanceof WorkflowUpdateHandled && $event->updateId() === $updateId) {
                return $event;
            }
        }

        return null;
    }

    public function result(ExecutionId $executionId, ?Duration $timeout = null): mixed
    {
        $executionId = $this->current($executionId);
        $ending = $this->ending($executionId);

        return match (true) {
            $ending instanceof ExecutionCompleted => $ending->result(),
            $ending instanceof WorkflowExecutionCancelled => throw new WorkflowCancelledException($executionId->toString(), $ending->reason()),
            $ending instanceof WorkflowExecutionFailed && \in_array($ending->kind(), [WorkflowExecutionFailed::KIND_TERMINATED_BY_PARENT, WorkflowExecutionFailed::KIND_TERMINATED], true) => throw new WorkflowTerminatedException($executionId->toString(), $ending->failureMessage()),
            $ending instanceof WorkflowExecutionFailed => throw WorkflowFailureRebuilder::toThrowable($ending, $this->activityFailure($executionId, $ending)),
            default => throw new WorkflowResultTimeout($executionId->toString(), $timeout ?? Duration::infinity()),
        };
    }

    public function cancel(ExecutionId $executionId, ?string $requestId = null): void
    {
        $executionId = $this->currentOpenRun($executionId);
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
        $executionId = $this->current($executionId);
        // A retry after a dispatch failed: the failure is journalled, the tail is not done.
        $half = $this->halfTerminated($executionId);
        if (null !== $half) {
            $this->finishTermination($executionId, $half);

            return;
        }
        $this->assertNotEnded($executionId);
        // A pass that was running no longer writes (DUR053): it cannot complete a terminated run.
        $journal = PassEventStore::open($this->events, $executionId);
        // A pass may have ended the run between the check and the claim.
        $this->assertNotEnded($executionId);
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

    private function assertNotEnded(ExecutionId $executionId): void
    {
        if (WorkflowRunStatus::Running !== $this->assertKnown($executionId)) {
            throw new WorkflowExecutionAlreadyEnded($executionId->toString());
        }
    }

    private function ending(ExecutionId $executionId): ExecutionCompleted|WorkflowExecutionCancelled|WorkflowExecutionFailed|WorkflowContinuedAsNew|null
    {
        $ending = null;
        foreach ($this->events->readStream($executionId) as $event) {
            if ($event instanceof ExecutionCompleted || $event instanceof WorkflowExecutionCancelled || $event instanceof WorkflowExecutionFailed || $event instanceof WorkflowContinuedAsNew) {
                $ending = $event;
            }
        }

        return $ending;
    }

    private function activityFailure(ExecutionId $executionId, WorkflowExecutionFailed $failure): ?ActivityFailed
    {
        $activityId = $failure->context()['activityId'] ?? null;
        $found = null;
        foreach ($this->events->readStream($executionId) as $event) {
            if ($event instanceof ActivityFailed && $event->activityId() === $activityId) {
                $found = $event;
            }
        }

        return $found;
    }

    /**
     * The run that is current in the chain that starts at the given one, which is the given one
     * unless it continued as new (design, task 1.7). The chain is read from the journal, run by run.
     *
     * @throws WorkflowExecutionNotFound
     * @throws WorkflowContinuedWithoutSuccessor a run continued as new and names no next run
     * @throws WorkflowSuccessorNotStarted       the next run it names does not exist
     */
    private function current(ExecutionId $executionId): ExecutionId
    {
        $status = $this->assertKnown($executionId);
        while (true) {
            $ending = $this->ending($executionId);
            if (!$ending instanceof WorkflowContinuedAsNew && WorkflowRunStatus::ContinuedAsNew !== $status) {
                return $executionId;
            }
            $next = $ending instanceof WorkflowContinuedAsNew ? $ending->newExecutionId() : null;
            if (null === $next) {
                throw new WorkflowContinuedWithoutSuccessor($executionId->toString());
            }
            $status = ($this->catalog->findRun($next) ?? throw new WorkflowSuccessorNotStarted($executionId->toString(), $next->toString()))->status;
            $executionId = $next;
        }
    }

    /**
     * A signal, an update or a cancel goes to the run that is current; it fails when that run has ended.
     */
    private function currentOpenRun(ExecutionId $executionId): ExecutionId
    {
        $current = $this->current($executionId);
        $this->assertNotEnded($current);

        return $current;
    }
}
