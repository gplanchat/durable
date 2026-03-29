<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Dispatcher no-op pour le mode inline.
 */
final class NullWorkflowResumeDispatcher implements WorkflowResumeDispatcher
{
    public function dispatchResume(string $executionId): void
    {
        // No-op
    }

    public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
    {
        // No-op
    }
}
