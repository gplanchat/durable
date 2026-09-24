<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * What happened to an action at this event (#261): the label names the thing, the phase says
 * where it stands. An event that is its own action — a signal, an update, a marker — has none.
 */
enum WorkflowRunEventPhase: string
{
    /** The workflow asked: an activity, a timer, a child, an operation, a cancellation. */
    case Requested = 'requested';
    /** A worker took it over. */
    case Started = 'started';
    /** An attempt, or the action, went wrong. */
    case Failed = 'failed';
    /** The outcome the workflow observes: a result, a firing, a cancellation. */
    case Settled = 'settled';
}
