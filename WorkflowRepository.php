<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\Exception\WorkflowClassNotFound;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowRepositoryNotDeclared;
use Gplanchat\Durable\Exception\WorkflowTypeMismatch;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Stub\WorkflowStub;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * The entry point of an application to the executions of one workflow class. A subclass carries
 * `#[AsWorkflowRepository]` and adds the finders named after the domain. The attribute is read on
 * the subclass itself and is not inherited.
 *
 * The constructor takes the run catalogue, the client port and, for a host that builds the
 * repository without a subclass, the workflow class. It is final, so a subclass cannot change it.
 *
 * @template T of object
 */
class WorkflowRepository
{
    /**
     * @param class-string<T>|null $workflow
     */
    final public function __construct(
        private readonly WorkflowRunCatalogInterface $catalog,
        private readonly WorkflowClientPort $client,
        /** The workflow served instead of the attribute's: how a host builds the repository that `#[RepositoryFor]` asks for. */
        private readonly ?string $workflow = null,
    ) {}

    /**
     * A stub for an execution that has not started. Contacts no backend; without an id, generates one.
     *
     * @return WorkflowStub<T>
     */
    public function create(?ExecutionId $id = null, ?WorkflowStartOptions $options = null): WorkflowStub
    {
        return new WorkflowStub($id ?? ExecutionId::generate(), $this->client, $this->workflowClass(), false, $options);
    }

    /**
     * A stub for an execution that has started. "Unknown" means unknown to the run catalogue: the
     * in-memory catalogue is empty in a process that did not run the workflow, and the DBAL,
     * Illuminate and Magento catalogues answer only once their projection is fed. A run whose type
     * the catalogue reports as another one, `UnknownWorkflow` included (the Temporal catalogue's
     * stand-in when the type is missing), raises {@see WorkflowTypeMismatch} on every backend.
     * The conformance test of task 1.1 must exercise every backend.
     *
     * @return WorkflowStub<T>
     *
     * @throws WorkflowExecutionNotFound
     * @throws WorkflowTypeMismatch
     * @throws WorkflowClassNotFound
     */
    public function get(ExecutionId $id): WorkflowStub
    {
        $run = $this->catalog->findRun($id) ?? throw new WorkflowExecutionNotFound($id->toString());
        $workflowClass = $this->workflowClass();

        try {
            $expected = (new WorkflowDefinitionLoader())->workflowTypeForClass($workflowClass);
        } catch (\ReflectionException $e) {
            throw WorkflowClassNotFound::fromReflection($workflowClass, $e);
        }
        if ($expected !== $run->workflowName) {
            throw new WorkflowTypeMismatch($id->toString(), $expected, $run->workflowName);
        }

        return new WorkflowStub($id, $this->client, $workflowClass, true);
    }

    /**
     * @return class-string<T>
     */
    private function workflowClass(): string
    {
        if (null !== $this->workflow) {
            /** @var class-string<T> */
            return $this->workflow;
        }

        $attributes = (new \ReflectionClass($this))->getAttributes(AsWorkflowRepository::class);
        if ([] === $attributes) {
            throw new WorkflowRepositoryNotDeclared(static::class);
        }

        /** @var class-string<T> */
        return $attributes[0]->newInstance()->workflow;
    }
}
