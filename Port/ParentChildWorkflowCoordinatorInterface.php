<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\ParentClosureReason;

/**
 * Applique les politiques parent → enfant après fermeture du workflow parent.
 */
interface ParentChildWorkflowCoordinatorInterface
{
    public function onParentClosed(string $parentExecutionId, ParentClosureReason $reason): void;
}
