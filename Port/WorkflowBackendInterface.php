<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Port for workflow backends (e.g. the local implementation, Temporal).
 *
 * This interface abstracts the starting and the management of workflows
 * for alternative implementations (e.g. a Temporal driver) without modifying
 * the core of the component.
 *
 * @see DUR002 (CQRS repositories, ports around the event journal)
 * @see OST001 Alternative durable execution backends (study)
 */
interface WorkflowBackendInterface
{
    /**
     * Starts a workflow execution.
     *
     * @param string      $executionId  Unique identifier of the execution
     * @param callable    $handler      Workflow handler (ExecutionContext, ExecutionRuntime) -> mixed
     * @param string|null $workflowType Registered type (toolbar / observability); optional
     *
     * @return mixed The workflow's result
     */
    public function start(string $executionId, callable $handler, ?string $workflowType = null): mixed;
}
