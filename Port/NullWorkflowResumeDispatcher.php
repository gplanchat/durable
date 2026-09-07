<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * No-op dispatcher for inline mode.
 */
final class NullWorkflowResumeDispatcher implements WorkflowResumeDispatcher
{
    public function dispatchResume(string $executionId, array $pendingUpdates = []): void
    {
        // No-op
    }

    public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
    {
        // No-op
    }
}
