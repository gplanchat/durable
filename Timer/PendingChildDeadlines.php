<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Timer;

use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowDeadline;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
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
        // The deadline is written before the child is scheduled, so that a crash between the two
        // leaves a deadline for a child that does not exist (never reported) instead of a child
        // with no deadline (never bounded). The journal has no append of several events at once.
        $armed = [];
        $pending = [];
        foreach ($journal->readStream($parentExecutionId) as $event) {
            if ($event instanceof ChildWorkflowDeadline) {
                $armed[$event->childExecutionId()->toString()] = $event->dueAt();
            }
            if ($event instanceof ChildWorkflowScheduled && isset($armed[$event->childExecutionId()->toString()])) {
                $pending[$event->childExecutionId()->toString()] = $armed[$event->childExecutionId()->toString()];
            }
            // An outcome closes the deadline written before it, so a reused child id starts afresh.
            if ($event instanceof ChildWorkflowCompleted || $event instanceof ChildWorkflowFailed) {
                unset($pending[$event->childExecutionId()->toString()], $armed[$event->childExecutionId()->toString()]);
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
