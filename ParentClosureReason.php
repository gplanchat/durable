<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Reason for the end of the parent run, for {@see ParentChildWorkflowCoordinatorInterface}.
 */
enum ParentClosureReason
{
    case CompletedSuccessfully;
    case Failed;
    case Cancelled;
}
