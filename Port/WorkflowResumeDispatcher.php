<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\Transport\AwaitedFact;

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
     * Sends, **now**, a resume that announces a fact not journalled yet (DUR050, DUR052): an
     * activity's outcome, a child's outcome, a signal, fired timers.
     *
     * Whoever appends the fact calls it before the append, then calls {@see dispatchResume()}
     * after. The resume waits until the fact is in the journal. Nothing may hold it until later
     * (the writer could die first), and where a resume runs inline (a `sync` route) it must not be
     * sent at all: it would always run before the append.
     */
    public function dispatchResumeAwaiting(string $executionId, AwaitedFact $fact): void;

    /**
     * Starts a new run (blank history) after a continue-as-new or equivalent.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void;
}
