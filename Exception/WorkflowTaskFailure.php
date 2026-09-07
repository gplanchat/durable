<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * This **attempt** failed; the execution itself is intact.
 *
 * Any other exception escaping the workflow code ends the execution in failure. That is the
 * right behaviour for a business failure: the workflow has finished, badly, and its history says
 * so.
 *
 * There are failures of another nature — the engine cannot replay this history with that code.
 * Ending the execution would then be the worst choice available: the cause is a deployment, and
 * a deployment can be rolled back. A dead execution cannot.
 *
 * This exception therefore asks to fail the task and leave the execution resumable: no command
 * is emitted, the history learns nothing of the attempt, and the server hands the task back.
 * Putting back the code that wrote the history is enough to start again.
 *
 * Measured, not assumed: probe 1.2 of `workflow-replay-divergence-guard` against a `start-dev`
 * 1.31.2 server. Throwing from the workflow code produced `WORKFLOW_TASK_COMPLETED` then
 * `WORKFLOW_EXECUTION_FAILED`, and putting the old code back resurrected nothing.
 *
 * Only the Temporal backend has the notion of a *task*. Elsewhere, this exception behaves like
 * any other — see the note in the change.
 */
final class WorkflowTaskFailure extends \RuntimeException {}
