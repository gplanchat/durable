<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Duration;
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
use Gplanchat\Durable\WorkflowIdReusePolicy;
use Gplanchat\Durable\WorkflowStartOptions;

/**
 * The in-memory implementation of {@see WorkflowClientPort}, first slice: start. A start inserts
 * the metadata row, fails on a second start of the id, then dispatches the run (#603).
 *
 * The other operations throw {@see UnsupportedByBackendException} until tasks 2.1 to 2.5. A start
 * with an option other than the id reuse policy throws {@see WorkflowStartOptionsRefused} until
 * task 2.6 is done.
 */
final class InMemoryWorkflowClient implements WorkflowClientPort
{
    public function __construct(
        private readonly WorkflowMetadataStore&ReopensCompletedWorkflowMetadata $metadata,
        private readonly WorkflowResumeDispatcher $dispatcher,
        private readonly WorkflowRunCatalogInterface $catalog,
    ) {}

    public function start(ExecutionId $executionId, string $workflowType, array $input = [], ?WorkflowStartOptions $options = null): ExecutionId
    {
        $refused = null === $options ? [] : self::refusedOptions($options);
        if ([] !== $refused) {
            throw new WorkflowStartOptionsRefused('in-memory', $refused);
        }
        if (!$this->metadata->insertIfAbsent($executionId, $workflowType, $input)
            && !$this->restarts($executionId, $workflowType, $input, $options?->workflowIdReusePolicy ?? WorkflowIdReusePolicy::AllowDuplicateFailedOnly)
        ) {
            throw new WorkflowExecutionAlreadyStarted($executionId->toString());
        }
        $this->dispatcher->dispatchNewWorkflowRun($executionId, $workflowType, $input);

        return $executionId;
    }

    /**
     * The id has a row. The policy judges the execution that ended (a running one, or one that
     * continued as new, refuses every start), and the store's conditional update arbitrates between
     * two starts that both pass the judgement.
     *
     * ponytail: the journal of the ended run is not cleared, so the new run replays what the old
     * one journaled (timers, activity results, signals). A run boundary in the journal is the
     * owner's decision.
     *
     * @param array<string, mixed> $input
     */
    private function restarts(ExecutionId $executionId, string $workflowType, array $input, WorkflowIdReusePolicy $policy): bool
    {
        $allowed = match ($policy) {
            WorkflowIdReusePolicy::RejectDuplicate => [],
            WorkflowIdReusePolicy::AllowDuplicate => [WorkflowRunStatus::Completed, WorkflowRunStatus::Failed, WorkflowRunStatus::Cancelled],
            WorkflowIdReusePolicy::AllowDuplicateFailedOnly => [WorkflowRunStatus::Failed, WorkflowRunStatus::Cancelled],
        };

        return \in_array($this->catalog->findRun($executionId)?->status, $allowed, true)
            && $this->metadata->reopenIfCompleted($executionId, $workflowType, $input);
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
        $this->assertNotEnded($executionId);

        throw $this->notYet('cancel');
    }

    public function terminate(ExecutionId $executionId, string $reason = ''): void
    {
        $this->assertNotEnded($executionId);

        throw $this->notYet('terminate');
    }

    /**
     * Only the id reuse policy takes effect on this backend (the timeouts follow, task 2.6): each
     * other option that differs from the defaults is refused.
     *
     * @return list<string>
     */
    private static function refusedOptions(WorkflowStartOptions $options): array
    {
        $defaults = WorkflowStartOptions::defaults();
        $honoured = ['workflowIdReusePolicy'];

        // Every public property: one added to WorkflowStartOptions later is refused, not accepted.
        return array_keys(array_filter(
            get_object_vars($options),
            static fn(mixed $value, string $name): bool => !\in_array($name, $honoured, true) && $value != $defaults->{$name},
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

    private function notYet(string $method): UnsupportedByBackendException
    {
        return UnsupportedByBackendException::forMethod('in-memory', $method, 'not implemented yet, see the workflow-repository change');
    }
}
