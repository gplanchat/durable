<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Stub;

use Gplanchat\Durable\Attribute\AsQueryMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsUpdateMethod;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowStubMethodNotDeclared;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;
use Gplanchat\Durable\WorkflowStartOptions;

/**
 * A handle on one execution of a workflow class, handed out by a
 * {@see \Gplanchat\Durable\WorkflowRepository}. Every operation goes through the
 * {@see WorkflowClientPort}.
 *
 * The methods the workflow declares with `#[AsSignalMethod]`, `#[AsQueryMethod]` and
 * `#[AsUpdateMethod]` are called on the stub; their arguments travel by parameter name. The names
 * `start`, `execute`, `result`, `cancel`, `terminate` and `executionId` belong to the stub
 * ({@see \Gplanchat\Durable\Workflow\ReservedStubMethods}).
 *
 * @template T of object
 */
final readonly class WorkflowStub
{
    /**
     * @param class-string<T> $workflowClass
     */
    public function __construct(
        private ExecutionId $executionId,
        private WorkflowClientPort $client,
        private string $workflowClass,
        private bool $started = false,
        private ?WorkflowStartOptions $options = null,
    ) {}

    public function executionId(): ExecutionId
    {
        return $this->executionId;
    }

    /**
     * Starts the execution without waiting for its result. The input maps to the entry method's
     * parameters by name.
     *
     * @return self<T> a stub bound to the started execution
     *
     * @throws WorkflowExecutionAlreadyStarted on a stub that already started, or from `get()`
     */
    public function start(mixed ...$input): self
    {
        if ($this->started) {
            throw new WorkflowExecutionAlreadyStarted($this->executionId->toString());
        }

        $loader = new WorkflowDefinitionLoader();
        $entry = $loader->resolveChildWorkflowMetadata($this->workflowClass);
        $payload = StubArguments::toPayload($entry['workflowMethod'], $input, WorkflowDefinitionLoader::inputParameters($entry['workflowMethod']));
        $this->client->start($this->executionId, $entry['workflowType'], $payload, $this->options);

        return new self($this->executionId, $this->client, $this->workflowClass, true, $this->options);
    }

    /**
     * Starts the execution and waits for its result.
     */
    public function execute(mixed ...$input): mixed
    {
        return $this->start(...$input)->result();
    }

    public function result(?Duration $timeout = null): mixed
    {
        return $this->client->result($this->executionId, $timeout);
    }

    public function cancel(?string $requestId = null): void
    {
        $this->client->cancel($this->executionId, $requestId);
    }

    public function terminate(string $reason = ''): void
    {
        $this->client->terminate($this->executionId, $reason);
    }

    /**
     * @param array<int|string, mixed> $arguments
     *
     * @throws WorkflowStubMethodNotDeclared
     */
    public function __call(string $name, array $arguments): mixed
    {
        foreach ((new \ReflectionClass($this->workflowClass))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (0 !== strcasecmp($method->getName(), $name)) {
                continue;
            }
            if ([] !== $attribute = $method->getAttributes(AsSignalMethod::class)) {
                $this->client->signal($this->executionId, $attribute[0]->newInstance()->signalName(), StubArguments::toPayload($method, $arguments));

                return null;
            }
            if ([] !== $attribute = $method->getAttributes(AsQueryMethod::class)) {
                return $this->client->query($this->executionId, $attribute[0]->newInstance()->name, StubArguments::toPayload($method, $arguments));
            }
            if ([] !== $attribute = $method->getAttributes(AsUpdateMethod::class)) {
                return $this->client->update($this->executionId, $attribute[0]->newInstance()->updateName(), StubArguments::toPayload($method, $arguments));
            }
        }

        throw new WorkflowStubMethodNotDeclared($this->workflowClass, $name);
    }
}
