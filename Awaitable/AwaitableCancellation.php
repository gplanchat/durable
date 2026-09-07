<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

use Gplanchat\Durable\ExecutionContext;

/**
 * Takes the operations still pending under an awaitable off the queue.
 *
 * Two callers needed it — the workflow cancellation, which empties whatever the fiber was
 * suspended on, and a composite that has reached its verdict and has no further use for its
 * losing branches. Each had its own version, and they did not descend to the same depth: the
 * composite's stopped at the first level, so that an `all()` bounded by a deadline left its
 * activities running. A single traversal, called from both sides.
 */
final class AwaitableCancellation
{
    private function __construct() {}

    /**
     * @param Awaitable<mixed> $awaitable
     * @param string           $reason a constant of {@see \Gplanchat\Durable\ActivityCancellationReason}
     *
     * @return list<string> ids of the operations taken off the queue
     */
    public static function cancelUnsettled(
        ExecutionContext $context,
        Awaitable $awaitable,
        string $reason,
    ): array {
        if ($awaitable instanceof CancellingCompositeAwaitable) {
            return self::cancelUnsettled($context, $awaitable->inner(), $reason);
        }

        if ($awaitable instanceof CompositeAwaitable) {
            $cancelled = [];
            foreach ($awaitable->members() as $member) {
                foreach (self::cancelUnsettled($context, $member, $reason) as $id) {
                    $cancelled[] = $id;
                }
            }

            return $cancelled;
        }

        if ($awaitable->isSettled()) {
            return [];
        }

        if ($awaitable instanceof ActivityAwaitable) {
            $context->cancelScheduledActivity($awaitable->activityId(), $reason);

            return [$awaitable->activityId()];
        }

        if ($awaitable instanceof TimerAwaitable) {
            $context->cancelScheduledTimer($awaitable->timerId(), $reason);

            return [$awaitable->timerId()];
        }

        if ($awaitable instanceof NexusOperationAwaitable) {
            $context->cancelScheduledNexusOperation($awaitable->operationId(), $reason);

            return [$awaitable->operationId()];
        }

        return [];
    }
}
