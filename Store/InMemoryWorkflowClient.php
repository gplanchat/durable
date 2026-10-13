<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\WorkflowStartOptions;

/**
 * The in-memory implementation of {@see WorkflowClientPort}, first slice: start. A start inserts
 * the metadata row, fails on a second start of the id, then dispatches the run (#603).
 *
 * The other operations throw {@see UnsupportedByBackendException} until tasks 2.1 to 2.5.
 */
final class InMemoryWorkflowClient implements WorkflowClientPort
{
    public function __construct(
        private readonly WorkflowMetadataStore $metadata,
        private readonly WorkflowResumeDispatcher $dispatcher,
    ) {}

    public function start(ExecutionId $executionId, string $workflowType, array $input = [], ?WorkflowStartOptions $options = null): ExecutionId
    {
        if (!$this->metadata->insertIfAbsent($executionId, $workflowType, $input)) {
            throw new WorkflowExecutionAlreadyStarted($executionId->toString());
        }
        $this->dispatcher->dispatchNewWorkflowRun($executionId, $workflowType, $input);

        return $executionId;
    }

    public function signal(ExecutionId $executionId, string $name, array $arguments = [], ?string $requestId = null): void
    {
        throw $this->notYet('signal');
    }

    public function query(ExecutionId $executionId, string $name, array $arguments = []): mixed
    {
        throw $this->notYet('query');
    }

    public function update(ExecutionId $executionId, string $name, array $arguments = [], ?string $updateId = null): mixed
    {
        throw $this->notYet('update');
    }

    public function result(ExecutionId $executionId, ?Duration $timeout = null): mixed
    {
        throw $this->notYet('result');
    }

    public function cancel(ExecutionId $executionId, ?string $requestId = null): void
    {
        throw $this->notYet('cancel');
    }

    public function terminate(ExecutionId $executionId, string $reason = ''): void
    {
        throw $this->notYet('terminate');
    }

    private function notYet(string $method): UnsupportedByBackendException
    {
        return UnsupportedByBackendException::forMethod('in-memory', $method, 'not implemented yet, see the workflow-repository change');
    }
}
