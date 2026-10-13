<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Query;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\Exception\WorkflowTaskFailure;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\EventStoreWorkflowLifecycle;

/**
 * The lifecycle of a read-only pass: no outcome is recorded, since the pass only builds the
 * workflow's state. A cancellation request the journal holds is applied, as the live pass applies
 * it, and a replay divergence fails the pass: a query on a history the code no longer matches
 * must not answer from a partial state.
 *
 * @internal
 */
final class InertLifecycle implements WorkflowLifecycleInterface
{
    public function __construct(private readonly EventStoreInterface $events) {}

    public function onBeforeRun(ExecutionId $executionId): void {}

    public function isCancellationPending(ExecutionId $executionId): bool
    {
        return (new EventStoreWorkflowLifecycle($this->events))->isCancellationPending($executionId);
    }

    public function onCancellationDelivered(ExecutionId $executionId, array $cancelledOperationIds): void {}

    public function onCancelled(ExecutionId $executionId, WorkflowCancelledFailure $failure): void {}

    public function onCompleted(ExecutionId $executionId, mixed $result): void {}

    public function onSuspended(ExecutionId $executionId, Awaitable $pending): void {}

    public function onContinuedAsNew(ExecutionId $executionId, ContinueAsNewRequested $request): void {}

    public function onFailed(ExecutionId $executionId, \Throwable $failure): void
    {
        if ($failure instanceof WorkflowTaskFailure) {
            throw $failure;
        }
    }
}
