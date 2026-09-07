<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Behaviour of child workflows when the parent finishes (aligned with Temporal's Parent Close Policy).
 */
enum ParentClosePolicy: string
{
    /** Terminate the child (child log: a controlled failure). */
    case Terminate = 'terminate';
    /** Leave the child alone. */
    case Abandon = 'abandon';
    /** Request cancellation ({@see Event\WorkflowCancellationRequested} event + resume). */
    case RequestCancel = 'request_cancel';
}
