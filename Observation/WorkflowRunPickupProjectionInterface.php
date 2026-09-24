<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * The optional third write of run observation: a worker picked the execution up (#447).
 *
 * Separate from {@see WorkflowRunProjectionInterface} so that its promise holds: a projection
 * implements two methods and reuses both decorators (DUR043). A projection that also implements this
 * one lets the run list tell a run nobody has picked up from a run waiting on a timer; one that does
 * not leaves that fact absent.
 *
 * {@see \Gplanchat\Durable\Store\ProjectingEventStore} calls it when the worker appends the first
 * `ExecutionStarted`, which a worker appends — except a continued run's, written by the worker that
 * ended its predecessor (#322).
 */
interface WorkflowRunPickupProjectionInterface
{
    /**
     * A worker picked the execution up. Recording it twice changes nothing.
     */
    public function recordPickup(string $executionId): void;
}
