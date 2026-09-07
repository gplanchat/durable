<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Standard reasons for cancelling an operation still pending (activity or timer).
 */
final class ActivityCancellationReason
{
    /** Loser of a {@see \Gplanchat\Durable\WorkflowEnvironment::any()}. */
    public const RACE_SUPERSEDED = 'race_superseded';

    /**
     * Withdrawn because cancellation of the execution was requested. Distinct from
     * {@see RACE_SUPERSEDED}: it rejects the wait with
     * {@see \Gplanchat\Durable\Exception\WorkflowCancelledFailure} and doubles as a
     * "cancellation already delivered" marker, so that it is not delivered twice.
     */
    public const WORKFLOW_CANCELLED = 'workflow_cancelled';

    private function __construct() {}
}
