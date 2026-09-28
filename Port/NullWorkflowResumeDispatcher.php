<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\Transport\AwaitedFact;

/**
 * No-op dispatcher for inline mode.
 */
final class NullWorkflowResumeDispatcher implements WorkflowResumeDispatcher
{
    public function dispatchResume(string $executionId, array $pendingUpdates = []): void
    {
        // No-op
    }

    public function dispatchResumeAwaiting(string $executionId, AwaitedFact $fact): void
    {
        // No-op
    }

    public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void
    {
        // No-op
    }
}
