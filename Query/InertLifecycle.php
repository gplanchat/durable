<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Query;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;

/**
 * The lifecycle of a read-only pass: no outcome is recorded, since the pass only builds the
 * workflow's state.
 *
 * @internal
 */
final class InertLifecycle implements WorkflowLifecycleInterface
{
    public function onBeforeRun(ExecutionId $executionId): void {}

    public function isCancellationPending(ExecutionId $executionId): bool
    {
        return false;
    }

    public function onCancellationDelivered(ExecutionId $executionId, array $cancelledOperationIds): void {}

    public function onCancelled(ExecutionId $executionId, WorkflowCancelledFailure $failure): void {}

    public function onCompleted(ExecutionId $executionId, mixed $result): void {}

    public function onSuspended(ExecutionId $executionId, Awaitable $pending): void {}

    public function onContinuedAsNew(ExecutionId $executionId, ContinueAsNewRequested $request): void {}

    public function onFailed(ExecutionId $executionId, \Throwable $failure): void {}
}
