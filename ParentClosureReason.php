<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Raison de la fin du run parent pour {@see ParentChildWorkflowCoordinatorInterface}.
 */
enum ParentClosureReason
{
    case CompletedSuccessfully;
    case Failed;
}
