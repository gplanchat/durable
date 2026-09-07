<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Debug;

/**
 * Optional observation of workflow / activity executions (Symfony toolbar, logs, etc.).
 */
interface WorkflowExecutionObserverInterface
{
    /**
     * @param string $workflowType Type registered in the WorkflowRegistry (or fallback label)
     */
    public function onWorkflowRun(string $executionId, string $workflowType, bool $isResume): void;

    /**
     * One activity execution attempt (includes the retries when there are several calls).
     *
     * @param class-string<\Throwable>|null $errorClass
     */
    public function onActivityExecuted(
        string $executionId,
        string $activityId,
        string $activityName,
        float $durationSeconds,
        bool $success,
        ?string $errorClass,
    ): void;
}
