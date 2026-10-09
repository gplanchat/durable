<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowRepositoryNotDeclared;
use Gplanchat\Durable\Exception\WorkflowTypeMismatch;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\Stub\WorkflowStub;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * The entry point of an application to the executions of one workflow class. A subclass carries
 * `#[AsWorkflowRepository]` and adds the finders named after the domain.
 *
 * @template T of object
 */
abstract class WorkflowRepository
{
    private readonly WorkflowDefinitionLoader $loader;

    public function __construct(
        private readonly WorkflowRunCatalogInterface $catalog,
        ?WorkflowDefinitionLoader $loader = null,
    ) {
        $this->loader = $loader ?? new WorkflowDefinitionLoader();
    }

    /**
     * A stub for an execution that has not started. Contacts no backend; without an id, generates one.
     */
    public function create(?ExecutionId $id = null): WorkflowStub
    {
        $this->workflowClass();

        return new WorkflowStub($id ?? ExecutionId::generate());
    }

    /**
     * A stub for an execution that has started.
     *
     * @throws WorkflowExecutionNotFound
     * @throws WorkflowTypeMismatch
     */
    public function get(ExecutionId $id): WorkflowStub
    {
        $run = $this->catalog->findRun($id) ?? throw new WorkflowExecutionNotFound($id->toString());
        $expected = $this->loader->workflowTypeForClass($this->workflowClass());
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
