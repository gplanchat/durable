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
     * Sends, **now**, a resume that announces the outcome of an activity not journalled yet (DUR050).
     *
     * The activity worker calls it before it appends the outcome, then calls
     * {@see dispatchResume()} after. The resume waits until the outcome is in the journal. Nothing
     * may hold it until later (the activity worker could die first), and where a resume runs
     * inline (a `sync` route) it must not be sent at all: it would always run before the append.
     */
    public function dispatchResumeAnnouncing(string $executionId, string $activityId): void;

    /**
     * Starts a new run (blank history) after a continue-as-new or equivalent.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void;
}
