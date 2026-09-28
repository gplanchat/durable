<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Timer;

use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * The timers a journal scheduled and neither fired nor cancelled: what fires next, what wakes the
 * run, and what a resume sent before the firing names (DUR052). Read in one place so the three
 * agree.
 */
final class PendingTimers
{
    /**
     * @return array<string, float> timer id => when it is due, in the order they were scheduled
     */
    public static function of(EventStoreInterface $journal, string $executionId): array
    {
        $pending = [];
        foreach ($journal->readStream($executionId) as $event) {
            if ($event instanceof TimerScheduled) {
                $pending[$event->timerId()] = $event->scheduledAt();
            }
            if ($event instanceof TimerCompleted || $event instanceof TimerCancelled) {
                unset($pending[$event->timerId()]);
            }
        }

        return $pending;
    }

    /**
     * @return list<string> the pending timers due at `$now`, in the order they were scheduled
     */
    public static function dueAt(EventStoreInterface $journal, string $executionId, float $now): array
    {
        return array_keys(array_filter(self::of($journal, $executionId), static fn(float $at): bool => $now >= $at));
    }
}
