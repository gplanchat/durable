<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * The optional fourth write of run observation: what a suspended execution waits on (#324).
 *
 * Optional for the same reason as {@see WorkflowRunPickupProjectionInterface}: a projection that
 * does not implement it leaves the fact absent. The core's `ResumeWorkflowHandler` calls it at each
 * suspension, and {@see \Gplanchat\Durable\Store\ProjectingEventStore} at each activity attempt.
 */
interface WorkflowRunWaitProjectionInterface
{
    /**
     * The latest wait replaces the previous one. Read only while the run is running.
     */
    public function recordWait(string $executionId, string $waitingOn): void;
}
