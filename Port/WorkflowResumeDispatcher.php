<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\ExecutionId;
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
    public function dispatchResume(ExecutionId $executionId, array $pendingUpdates = []): void;

    /**
     * Sends, **now**, a resume that announces a fact not journalled yet (DUR050, DUR052): an
     * activity's outcome, a child's outcome, a signal, fired timers.
     *
     * Whoever appends the fact calls it before the append, then calls {@see dispatchResume()}
     * after. The resume waits until the fact is in the journal. Nothing may hold it until later
     * (the writer could die first), and where a resume runs inline (a `sync` route) it must not be
     * sent at all: it would always run before the append.
     */
    public function dispatchResumeAwaiting(ExecutionId $executionId, AwaitedFact $fact): void;

    /**
     * Starts a new run (blank history) after a continue-as-new or equivalent.
     *
     * After a continue-as-new, a redelivered resume of the old run calls it again for the same
     * run (#881): the second call sends a second resume of that run, which replays it.
     *
     * The metadata row is written only when the run has none (#918). A row that exists is left as
     * it is, type, payload and `completed` alike: rewriting it would reopen a run that finished in
     * between.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatchNewWorkflowRun(ExecutionId $executionId, string $workflowType, array $payload): void;
}
