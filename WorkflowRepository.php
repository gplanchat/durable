<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\Exception\WorkflowClassNotFound;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowRepositoryNotDeclared;
use Gplanchat\Durable\Exception\WorkflowTypeMismatch;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Stub\WorkflowStub;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * The entry point of an application to the executions of one workflow class. A subclass carries
 * `#[AsWorkflowRepository]` and adds the finders named after the domain. The attribute is read on
 * the subclass itself and is not inherited.
 *
 * The constructor takes the run catalogue only and is final, so a subclass cannot change it. The
 * client port (task 1.1) will join the repository as a collaborator that leaves this constructor
 * as it is.
 *
 * @template T of object
 */
abstract class WorkflowRepository
{
    final public function __construct(
        private readonly WorkflowRunCatalogInterface $catalog,
    ) {}

    /**
     * A stub for an execution that has not started. Contacts no backend; without an id, generates one.
     */
    public function create(?ExecutionId $id = null): WorkflowStub
    {
        // Called only to refuse a repository that carries no attribute.
        $this->workflowClass();

        return new WorkflowStub($id ?? ExecutionId::generate());
    }

    /**
     * A stub for an execution that has started. "Unknown" means unknown to the run catalogue: the
     * in-memory catalogue is empty in a process that did not run the workflow, and the DBAL,
     * Illuminate and Magento catalogues answer only once their projection is fed. A run whose type
     * the catalogue reports as another one, `UnknownWorkflow` included (the Temporal catalogue's
     * stand-in when the type is missing), raises {@see WorkflowTypeMismatch} on every backend.
     * The conformance test of task 1.1 must exercise every backend.
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

        return new WorkflowStub($id);
    }

    /**
     * @return class-string<T>
     */
    private function workflowClass(): string
    {
        $attributes = (new \ReflectionClass($this))->getAttributes(AsWorkflowRepository::class);
        if ([] === $attributes) {
            throw new WorkflowRepositoryNotDeclared(static::class);
        }

        /** @var class-string<T> */
        return $attributes[0]->newInstance()->workflow;
    }
}
