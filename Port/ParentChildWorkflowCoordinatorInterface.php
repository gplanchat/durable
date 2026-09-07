<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\ParentClosureReason;

/**
 * Applies the parent → child policies after the parent workflow closes.
 */
interface ParentChildWorkflowCoordinatorInterface
{
    public function onParentClosed(string $parentExecutionId, ParentClosureReason $reason): void;
}
