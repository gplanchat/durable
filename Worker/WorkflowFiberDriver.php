<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Worker;

use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\AwaitableCancellation;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * The single driver of a run's fiber: start, replay of the already-settled awaitables, stop on
 * a new command, termination.
 *
 * This loop existed in two copies — {@see \Gplanchat\Durable\ExecutionEngine} and the Temporal
 * runner — written separately, with divergent `catch` chains: the lifecycle outcomes added to
 * one were missing from the other. They now go through
 * {@see WorkflowLifecycleInterface}, of which each backend is an implementation.
 */
final class WorkflowFiberDriver
{
    public function __construct(
        private readonly WorkflowLifecycleInterface $lifecycle,
    ) {}

    /**
     * @return mixed The handler's result if it ran to the end, null otherwise (suspension,
     *               outcome raised by the port)
     */
    public function run(
        string $executionId,
        ExecutionContext $context,
        WorkflowEnvironment $environment,
        callable $handler,
    ): mixed {
        $this->lifecycle->onBeforeRun($executionId);

        // Second argument deliberately not declared by most handlers: PHP accepts extra
        // arguments on a userland function, so a closure that takes only the environment keeps
        // working. Only the loader's factory declares it.
        $queries = $context->queryHandlers();
        $fiber = new \Fiber(static fn() => $handler($environment, $queries));

        // At most one delivery per run of the driver: having picked the cancellation up, the
        // handler may compensate by awaiting new operations, and those must not be cancelled in
        // their turn.
        $cancellationDelivered = false;

        try {
            $suspended = $fiber->start();
        } catch (\Throwable $e) {
            $this->dispatchThrowable($executionId, $e);

            return null;
        }

        while ($fiber->isSuspended()) {
            if (!$suspended instanceof Awaitable) {
                break;
            }

            if (!$suspended->isSettled()) {
                // Cancellation requested while the fiber awaits: deliver it HERE, the way
                // Temporal delivers a CanceledFailure, so the workflow can compensate. The pending
                // operation is cancelled with the workflow_cancelled reason, which doubles as the
                // delivery trace — on replay, the awaitable is rejected by the journal at the same
                // place.
                if (!$cancellationDelivered && $this->lifecycle->isCancellationPending($executionId)) {
                    $cancellationDelivered = true;
                    $failure = new WorkflowCancelledFailure($executionId, ActivityCancellationReason::WORKFLOW_CANCELLED);
                    $this->lifecycle->onCancellationDelivered($executionId, self::cancelPending($context, $suspended));

                    try {
                        $suspended = $fiber->throw($failure);
                    } catch (\Throwable $e) {
                        $this->dispatchThrowable($executionId, $e);

                        return null;
                    }

                    continue;
                }

                // New command: already stacked in the WorkflowCommandBufferInterface.
                $this->lifecycle->onSuspended($executionId, $suspended);

                return null;
            }

            // Replay: the awaitable was settled even before the await, resume straight away.
            try {
                $suspended = $fiber->resume();
            } catch (\Throwable $e) {
                $this->dispatchThrowable($executionId, $e);

                return null;
            }
        }

        if ($fiber->isTerminated()) {
            $result = $fiber->getReturn();
            $this->lifecycle->onCompleted($executionId, $result);

            return $result;
        }

        return null;
    }

    private function dispatchThrowable(string $executionId, \Throwable $e): void
    {
        if ($e instanceof ContinueAsNewRequested) {
            $this->lifecycle->onContinuedAsNew($executionId, $e);

            return;
        }

        if ($e instanceof WorkflowCancelledFailure) {
            // The workflow did not swallow it: the execution ends cancelled, not failed.
            $this->lifecycle->onCancelled($executionId, $e);

            return;
        }

        $this->lifecycle->onFailed($executionId, $e);
    }

    /**
     * Removes from the queue the operation the fiber is waiting on. A composite wraps several of
     * them: every branch still pending is cancelled.
     *
     * @param Awaitable<mixed> $pending
     *
     * @return list<string> identifiers of the removed operations
     */
    private static function cancelPending(ExecutionContext $context, Awaitable $pending): array
    {
        return AwaitableCancellation::cancelUnsettled($context, $pending, ActivityCancellationReason::WORKFLOW_CANCELLED);
    }
}
