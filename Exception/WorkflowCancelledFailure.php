<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Thrown **inside the fiber**, at the point of waiting, when cancellation of the execution has
 * been requested.
 *
 * The equivalent of Temporal's `CanceledFailure`: the workflow may catch it to compensate, then
 * rethrow it (the execution ends cancelled) or swallow it and end normally — a workflow is
 * entitled to ignore a cancellation.
 *
 * Delivered **exactly once** per execution: the pending operation is cancelled with the reason
 * {@see \Gplanchat\Durable\ActivityCancellationReason::WORKFLOW_CANCELLED}, which serves both
 * as the trace of delivery and as the source of the rejection on replay — the workflow therefore
 * throws the same exception at the same place, with no extra marker on the in-memory side.
 */
final class WorkflowCancelledFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $reason,
    ) {
        parent::__construct(\sprintf('Workflow %s cancellation requested: %s', $executionId, $reason));
    }
}
