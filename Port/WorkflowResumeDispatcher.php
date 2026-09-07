<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Port for dispatching the resume of a workflow (distributed mode).
 *
 * @see DUR021 Symfony Messenger integration (distributed resume)
 */
interface WorkflowResumeDispatcher
{
    /**
     * @param list<array{name: string, arguments: array<string, mixed>}> $pendingUpdates updates to
     *        hand back to the execution for the pass this resume triggers
     */
    public function dispatchResume(string $executionId, array $pendingUpdates = []): void;

    /**
     * Starts a new run (blank history) after a continue-as-new or equivalent.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void;
}
