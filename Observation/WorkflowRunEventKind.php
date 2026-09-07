<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * What kind an event is — and, through it, the action it opens.
 *
 * ⚠ **This is no longer a lane.** The frieze used to file by kind — "activities", "signals" — which
 * forced the operator to piece three rows back together by eye to work out how long *one* activity
 * had lasted. It now files by **action** ({@see RunTimeline}), and this enumeration serves there
 * only to colour: an action has the kind of the event that opens it.
 *
 * The set is the one dashboards distinguish, not the one backends record: timers, child workflows
 * and side effects are indeed journalled, and fall through to `Other` — **listed, not hidden**:
 * making them disappear would make the order of events lie, and that order is what an operator
 * comes to read first. They do get their row, since the row comes from the action and not from the
 * kind.
 *
 * `Query` is never produced by the journal: no query is recorded there, they are answered on the
 * fly. Only the Temporal backend can produce one, and that is a fact one backend has and the other
 * does not — not a gap to be filled.
 */
enum WorkflowRunEventKind: string
{
    case Execution = 'execution';
    case Activity = 'activity';
    case Signal = 'signal';
    case Update = 'update';
    case Query = 'query';

    /**
     * The one place in an execution where the wait is **served by someone else** — another team,
     * another namespace, another deployment. Hence a kind of its own rather than `Other`: an
     * operator who sees a blocked workflow without seeing the operation it is waiting on will
     * look for the failure in their own system, when it is on the outside.
     */
    case Nexus = 'nexus';
    case Other = 'other';
}
