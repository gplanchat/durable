<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * The outcome of an execution, as an operator reads it.
 *
 * Backed by a string because it is persisted by the DBAL backend's projection and rendered as is in
 * a filter URL: the value is part of the contract, not just the case.
 *
 * `ContinuedAsNew` is a **normal** ending, distinct from `Failed`: the component treats a
 * continue-as-new as a fresh execution — new id, new metadata, redispatch — and the execution that
 * hands over has finished without error. Conflating them would show perfectly healthy long-running
 * workflows in red.
 */
enum WorkflowRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case ContinuedAsNew = 'continued_as_new';

    /**
     * Is an execution still liable to make progress?
     */
    public function isRunning(): bool
    {
        return self::Running === $this;
    }
}
