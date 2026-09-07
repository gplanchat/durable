<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Timer;

use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Delay until the next uncompleted timer (for {@see \Symfony\Component\Messenger\Stamp\DelayStamp}).
 */
/*
 * Moved down from the bundle package into the core: it imported nothing from Symfony — only the
 * timer events and the event store port — and `InMemoryWorkflowRunner`, which is core, called it.
 * A host without the bundle therefore took a fatal error on the first resume that had to jump to
 * the next timer, and under Symfony nothing showed.
 */
final class TimerWakeDelayCalculator
{
    /**
     * @return int milliseconds until {@see TimerScheduled::scheduledAt()} of the next pending timer
     *             (neither completed nor cancelled), or null if there is none
     */
    public static function millisecondsUntilNextTimerDue(EventStoreInterface $store, string $executionId, float $nowSeconds): ?int
    {
        $scheduled = [];
        $completed = [];
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof TimerScheduled) {
                $scheduled[$event->timerId()] = $event->scheduledAt();
            }
            if ($event instanceof TimerCompleted || $event instanceof TimerCancelled) {
                $completed[$event->timerId()] = true;
            }
        }

        $pending = [];
        foreach ($scheduled as $id => $at) {
            if (!isset($completed[$id])) {
                $pending[] = $at;
            }
        }

        if ([] === $pending) {
            return null;
        }

        $minDue = min($pending);
        $sec = max(0.0, $minDue - $nowSeconds);

        return (int) ceil($sec * 1000.0);
    }
}
