<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\ExecutionId;

/**
 * Wakes nothing: for a host that runs everything inside one process, where the runner advances
 * its own timers without anyone having to remind it of them.
 */
final class NullWorkflowTimerDispatcher implements WorkflowTimerDispatcher
{
    public function dispatchTimerFire(ExecutionId|string $executionId, int $delayMs = 0): void {}
}
