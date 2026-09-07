<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\Stub\StubArguments;

/**
 * Workflow-side scheduling proxy for running a typed child workflow.
 *
 * Every call to the WorkflowMethod method starts the child and returns an `Awaitable`: it is the
 * caller that awaits, which makes the child composable — a race, a quorum, a deadline. A stub
 * that awaited on the caller's behalf could not enter any assembly.
 *
 * @template TWorkflow of object
 */
final class ChildWorkflowStub
{
    private string $workflowType;

    private \ReflectionMethod $workflowMethod;

    /**
     * @param class-string<TWorkflow> $workflowClass
     */
    public function __construct(
        private readonly ChildWorkflowSchedulerInterface $scheduler,
        private readonly string $workflowClass,
        WorkflowDefinitionLoader $loader,
        private readonly ?ChildWorkflowOptions $options = null,
    ) {
        $metadata = $loader->resolveChildWorkflowMetadata($workflowClass);
        $this->workflowType = $metadata['workflowType'];
        $this->workflowMethod = $metadata['workflowMethod'];
    }

    /**
     * @param array<int, mixed> $arguments
     */
    /**
     * @return \Gplanchat\Durable\Awaitable\Awaitable<mixed>
     */
    public function __call(string $name, array $arguments): \Gplanchat\Durable\Awaitable\Awaitable
    {
        if ($name !== $this->workflowMethod->getName()) {
            throw new \BadMethodCallException(\sprintf('Method %s::%s() is not the workflow entry point (expected %s).', $this->workflowClass, $name, $this->workflowMethod->getName()));
        }

        $input = $this->argumentsToInput($arguments);

        return $this->scheduler->startChildWorkflow($this->workflowType, $input, $this->options);
    }

    /**
     * @param array<int, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function argumentsToInput(array $arguments): array
    {
        return StubArguments::toPayload($this->workflowMethod, $arguments);
    }
}
