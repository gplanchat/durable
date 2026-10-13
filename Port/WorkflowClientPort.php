<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowResultTimeout;
use Gplanchat\Durable\Exception\WorkflowStartOptionsRefused;
use Gplanchat\Durable\Exception\WorkflowTerminatedException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\WorkflowStartOptions;

/**
 * What an application does to an execution, keyed by {@see ExecutionId} and identical on every
 * backend (openspec change `workflow-repository`). The workflow stub and the repository sit on
 * top of it; the repository checks the workflow type through the run catalogue, so the port takes
 * none.
 *
 * Every operation except {@see start()} throws {@see WorkflowExecutionNotFound} for an id no
 * execution holds. Signal, update, cancel and terminate throw {@see WorkflowExecutionAlreadyEnded}
 * for an execution that has ended.
 *
 * The optional trailing `$requestId` and `$updateId` match the Temporal client's own parameters.
 * Adding them later would break every implementation.
 */
interface WorkflowClientPort
{
    /**
     * Starts an execution and returns its id without waiting for its result.
     *
     * @param array<string, mixed> $input
     *
     * @throws WorkflowExecutionAlreadyStarted the first execution keeps its type, input and progress
     * @throws WorkflowStartOptionsRefused     naming every option the backend cannot honour; nothing started
     */
    public function start(ExecutionId $executionId, string $workflowType, array $input = [], ?WorkflowStartOptions $options = null): ExecutionId;

    /**
     * @param array<string, mixed> $arguments
     *
     * @throws WorkflowExecutionNotFound
     * @throws WorkflowExecutionAlreadyEnded
     */
    public function signal(ExecutionId $executionId, string $name, array $arguments = [], ?string $requestId = null): void;

    /**
     * Adds no event to the execution's history.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws WorkflowExecutionNotFound
     */
    public function query(ExecutionId $executionId, string $name, array $arguments = []): mixed;

    /**
     * Returns the result of this update, even when another caller sent the same name and arguments.
     *
     * @param array<string, mixed> $arguments
     *
     * @throws WorkflowExecutionNotFound
     * @throws WorkflowExecutionAlreadyEnded
     */
    public function update(ExecutionId $executionId, string $name, array $arguments = [], ?string $updateId = null): mixed;

    /**
     * Waits for the result. A failed execution throws its failure. The spec is silent on a
     * cancelled and a terminated execution; they throw what the Temporal client's
     * `pollForCompletion()` throws today.
     *
     * @param Duration|null $timeout null waits without a bound
     *
     * @throws WorkflowExecutionNotFound
     * @throws WorkflowResultTimeout
     * @throws WorkflowCancelledException
     * @throws WorkflowTerminatedException
     */
    public function result(ExecutionId $executionId, ?Duration $timeout = null): mixed;

    /**
     * Requests cancellation; the workflow receives it where it waits.
     *
     * @throws WorkflowExecutionNotFound
     * @throws WorkflowExecutionAlreadyEnded
     */
    public function cancel(ExecutionId $executionId, ?string $requestId = null): void;

    /**
     * Ends the execution without running more of its code.
     *
     * @throws WorkflowExecutionNotFound
     * @throws WorkflowExecutionAlreadyEnded
     */
    public function terminate(ExecutionId $executionId, string $reason = ''): void;
}
