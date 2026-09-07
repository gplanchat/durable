<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Debug;

/**
 * The observer for when nobody observes.
 *
 * Observation is optional by contract, but the three services on the hot path,
 * {@see \Gplanchat\Durable\ExecutionRuntime}, {@see \Gplanchat\Durable\ExecutionEngine} and
 * {@see \Gplanchat\Durable\Worker\ActivityMessageProcessor}, take one by injection. So they need
 * somebody, including in production where there is no profiler to feed.
 *
 * Doing nothing is a real behaviour here rather than a hole plugged to satisfy a signature: an
 * execution nobody watches runs the same. It is the same reason that makes `Psr\Log\NullLogger` a
 * legitimate null object.
 */
final class NullWorkflowExecutionObserver implements WorkflowExecutionObserverInterface
{
    #[\Override]
    public function onWorkflowRun(string $executionId, string $workflowType, bool $isResume): void {}

    #[\Override]
    public function onActivityExecuted(
        string $executionId,
        string $activityId,
        string $activityName,
        float $durationSeconds,
        bool $success,
        ?string $errorClass,
    ): void {}
}
