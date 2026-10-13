<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowResultTimeout;
use Gplanchat\Durable\Exception\WorkflowStartOptionsRefused;
use Gplanchat\Durable\Exception\WorkflowTerminatedException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\WorkflowFailureRebuilder;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\WorkflowStartOptions;

/**
 * The in-memory implementation of {@see WorkflowClientPort}, first slice: start. A start inserts
 * the metadata row, fails on a second start of the id, then dispatches the run (#603).
 *
 * Result polls the journal for the ending (task 2.3). The other operations throw {@see UnsupportedByBackendException} until tasks 2.1 to 2.5. A start
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
        $startedAt = microtime(true);

        // ponytail: polls the journal; nothing else runs in this process while we wait, so it
        // only returns early for an ending another process (a worker) appends. Without a bound
        // it waits for as long as the execution runs.
        while (null === ($ending = $this->ending($executionId))) {
            if (null !== $timeout && $timeout->hasElapsedSince($startedAt, microtime(true))) {
                throw new WorkflowResultTimeout($executionId->toString(), $timeout);
            }
            usleep(10_000);
        }

        return match (true) {
            $ending instanceof ExecutionCompleted => $ending->result(),
            $ending instanceof WorkflowExecutionCancelled => throw new WorkflowCancelledException($executionId->toString(), $ending->reason()),
            WorkflowExecutionFailed::KIND_TERMINATED_BY_PARENT === $ending->kind() => throw new WorkflowTerminatedException($executionId->toString(), $ending->failureMessage()),
            default => throw WorkflowFailureRebuilder::toThrowable($ending),
        };
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

    private function ending(ExecutionId $executionId): ExecutionCompleted|WorkflowExecutionCancelled|WorkflowExecutionFailed|null
    {
        $ending = null;
        foreach ($this->events->readStream($executionId) as $event) {
            if ($event instanceof ExecutionCompleted || $event instanceof WorkflowExecutionCancelled || $event instanceof WorkflowExecutionFailed) {
                $ending = $event;
            }
        }

        return $ending;
    }

    private function notYet(string $method): UnsupportedByBackendException
    {
        return UnsupportedByBackendException::forMethod('in-memory', $method, 'not implemented yet, see the workflow-repository change');
    }
}
