<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;

/**
 * The lifecycle outcomes of a run, as the backend records them.
 *
 * {@see \Gplanchat\Durable\Worker\WorkflowFiberDriver} drives the fiber — start, replay,
 * suspension, termination — identically for every backend; this port carries the only decisions
 * that belong to them in their own right: the in-memory backend journals events
 * ({@see \Gplanchat\Durable\Store\EventStoreWorkflowLifecycle}), the Temporal backend stacks
 * commands ({@see \Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowLifecycle}).
 *
 * Several methods are entitled to **throw** in order to interrupt the run: that is how the
 * in-memory backend signals suspension, cancellation and failure to its caller, where the
 * Temporal backend hands control back to let the current task's commands go out.
 */
interface WorkflowLifecycleInterface
{
    /**
     * Before any fiber starts.
     *
     * @throws \Throwable to prevent the run from starting
     */
    public function onBeforeRun(string $executionId): void;

    /**
     * Has a cancellation been requested and **not yet delivered** to the workflow?
     *
     * Delivery must be unique per execution: since the fiber is replayed from the start on every
     * task, a permanent "yes" would raise the cancellation inside the compensation waits
     * themselves, and the workflow could never compensate.
     */
    public function isCancellationPending(string $executionId): bool;

    /**
     * The cancellation went through the handler without being swallowed: the execution ends
     * cancelled.
     *
     * @throws \Throwable to propagate the ending to the caller
     */
    /**
     * The cancellation has just been raised inside the fiber; `$cancelledOperationIds` lists the
     * operations withdrawn on that occasion.
     *
     * On replay the backend must be able to reject **those same** operations with the same
     * exception: without which the workflow's `catch` would no longer match and the compensation
     * would diverge from one task to the next.
     *
     * @param list<string> $cancelledOperationIds
     */
    public function onCancellationDelivered(string $executionId, array $cancelledOperationIds): void;

    public function onCancelled(string $executionId, WorkflowCancelledFailure $failure): void;

    /**
     * The handler ran all the way through.
     */
    public function onCompleted(string $executionId, mixed $result): void;

    /**
     * The fiber is waiting on an unsettled awaitable; the matching command is already in the buffer.
     *
     * @param Awaitable<mixed> $pending
     *
     * @throws \Throwable to signal the suspension to the caller rather than hand control back
     */
    public function onSuspended(string $executionId, Awaitable $pending): void;

    /**
     * **Normal** termination: the current run stops in order to chain a new one.
     *
     * @throws \Throwable to propagate the request to the caller
     */
    public function onContinuedAsNew(string $executionId, ContinueAsNewRequested $request): void;

    /**
     * The handler did not handle an error.
     *
     * @throws \Throwable to propagate the failure to the caller
     */
    public function onFailed(string $executionId, \Throwable $failure): void;
}
