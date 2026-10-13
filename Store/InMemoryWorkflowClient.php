<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Exception\DurableUpdateFailedException;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowStartOptionsRefused;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\WorkflowStartOptions;

/**
 * The in-memory implementation of {@see WorkflowClientPort}, start, signal and update. A start inserts
 * the metadata row, fails on a second start of the id, then dispatches the run (#603).
 *
 * The other operations throw {@see UnsupportedByBackendException} until tasks 2.1 to 2.5. A start
 * with options throws {@see WorkflowStartOptionsRefused} until task 2.6.
 */
final class InMemoryWorkflowClient implements WorkflowClientPort
{
    public function __construct(
        private readonly WorkflowMetadataStore $metadata,
        private readonly WorkflowResumeDispatcher $dispatcher,
        private readonly WorkflowRunCatalogInterface $catalog,
        private readonly EventStoreInterface $events,
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

    /**
     * @throws UnsupportedByBackendException the run continued as new
     */
    public function signal(ExecutionId $executionId, string $name, array $arguments = [], ?string $requestId = null): void
    {
        $this->assertAcceptsMessages($executionId, 'signal');
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

        throw $this->notYet('query');
    }

    /**
     * A pass applies a pending update only inside a condition await. An update the workflow does not
     * declare, or one sent while it waits on a timer or an activity, is applied by no pass: the
     * caller receives a {@see DurableUpdateFailedException} whose reason is
     * {@see UpdateFailureReason::NotHandled}, and the update is not queued for later.
     *
     * @throws UnsupportedByBackendException   the run continued as new
     * @throws DurableUpdateFailedException
     */
    public function update(ExecutionId $executionId, string $name, array $arguments = [], ?string $updateId = null): mixed
    {
        $this->assertAcceptsMessages($executionId, 'update');
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
        $this->assertKnown($executionId);

        throw $this->notYet('result');
    }

    public function cancel(ExecutionId $executionId, ?string $requestId = null): void
    {
        $this->assertNotEnded($executionId);

        throw $this->notYet('cancel');
    }

    public function terminate(ExecutionId $executionId, string $reason = ''): void
    {
        $this->assertNotEnded($executionId);

        throw $this->notYet('terminate');
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
     * A signal or an update goes to the run that is current. This backend does not follow a
     * continue-as-new chain yet (task 1.7): delivering to the old run would lose the message.
     */
    private function assertAcceptsMessages(ExecutionId $executionId, string $method): void
    {
        $this->assertNotEnded($executionId);
        if (WorkflowRunStatus::ContinuedAsNew === $this->assertKnown($executionId)) {
            throw UnsupportedByBackendException::forMethod('in-memory', $method, 'the run continued as new and the chain is not followed yet, see the workflow-repository change');
        }
    }

    private function notYet(string $method): UnsupportedByBackendException
    {
        return UnsupportedByBackendException::forMethod('in-memory', $method, 'not implemented yet, see the workflow-repository change');
    }
}
