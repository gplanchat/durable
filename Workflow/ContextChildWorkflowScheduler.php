<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\ExecutionContext;

/**
 * The child-start port, wired onto the execution context.
 *
 * Built by {@see \Gplanchat\Durable\WorkflowEnvironment::childWorkflowStub()} and never
 * returned: a workflow never receives the context, so it cannot name a child type by a string.
 *
 * @internal
 */
final class ContextChildWorkflowScheduler implements ChildWorkflowSchedulerInterface
{
    public function __construct(
        private readonly ExecutionContext $context,
    ) {}

    public function startChildWorkflow(string $childWorkflowType, array $input, ?ChildWorkflowOptions $options): Awaitable
    {
        // `ExecutionContext::executeChildWorkflow()` schedules and returns an awaitable despite
        // its name: it was the environment that awaited on top of it.
        return $this->context->executeChildWorkflow($childWorkflowType, $input, $options);
    }
}
