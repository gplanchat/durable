<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * The write side of run observation (DUR037).
 *
 * The catalog answers "which executions exist, and what became of them"; this port is what teaches
 * it that. The two are kept apart because the dashboard has no reason to be able to write, and
 * because a backend may perfectly well feed a projection it does not read.
 *
 * Two methods, and that is all the
 * {@see \Gplanchat\Durable\Store\ProjectingEventStore} and
 * {@see \Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore} decorators call. They already
 * carried these names on the SQL side before being an interface — extracting it renamed nothing.
 *
 * @see DUR037
 */
interface WorkflowRunProjectionInterface
{
    /**
     * An execution starts. The **name** can only come from the metadata store:
     * `ExecutionStarted` does not carry the workflow type.
     */
    public function recordStart(string $executionId, string $workflowType): void;

    /**
     * What the execution became. It comes from the journal, the only place where the outcome is a
     * fact.
     */
    public function recordOutcome(string $executionId, WorkflowRunStatus $status): void;
}
