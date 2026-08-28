<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Ne réveille rien : pour un hôte qui exécute tout dans un processus, où le runner avance ses
 * propres minuteries sans que personne ait à les lui rappeler.
 */
final class NullWorkflowTimerDispatcher implements WorkflowTimerDispatcher
{
    public function dispatchTimerFire(string $executionId, int $delayMs = 0): void {}
}
