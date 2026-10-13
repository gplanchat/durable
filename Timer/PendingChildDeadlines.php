<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Timer;

use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowDeadline;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * The child deadlines a parent's journal holds whose child has no outcome yet: what the wake-up
 * of a parent that waits on a child reads, next to {@see PendingTimers}.
 *
 * @internal
 */
final class PendingChildDeadlines
{
    private function __construct() {}

    /**
     * @return array<string, float> child execution id => when it is due, in the order they were scheduled
     */
    public static function of(EventStoreInterface $journal, ExecutionId $parentExecutionId): array
    {
        $pending = [];
        foreach ($journal->readStream($parentExecutionId) as $event) {
            if ($event instanceof ChildWorkflowDeadline) {
                $pending[$event->childExecutionId()->toString()] = $event->dueAt();
            }
            // An outcome closes the deadline written before it, so a reused child id starts afresh.
            if ($event instanceof ChildWorkflowCompleted || $event instanceof ChildWorkflowFailed) {
                unset($pending[$event->childExecutionId()->toString()]);
            }
        }

        return $pending;
    }

    /**
     * @return list<string> the children whose deadline fell at `$now`, in the order they were scheduled
     */
    public static function dueAt(EventStoreInterface $journal, ExecutionId $parentExecutionId, float $now): array
    {
        return array_keys(array_filter(self::of($journal, $parentExecutionId), static fn(float $at): bool => $now >= $at));
    }
}
