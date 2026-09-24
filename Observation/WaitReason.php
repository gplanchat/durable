<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

use Gplanchat\Durable\Awaitable\ActivityAwaitable;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\AwaitableInspector;
use Gplanchat\Durable\Awaitable\CancellingCompositeAwaitable;
use Gplanchat\Durable\Awaitable\CompositeAwaitable;
use Gplanchat\Durable\Awaitable\TimerAwaitable;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * What a suspended run waits on, in the words the run list shows (#324).
 *
 * The awaitables carry only identifiers; the name of an activity and the deadline of a timer are
 * in the journal, so it is read — once, and only when the wait is not a condition. A condition
 * names where it is written: a signal wait is a condition, and nothing records which signal it
 * expects.
 *
 * ponytail: one more read of the stream per suspension on a timer or an activity; pass the replayed
 * history in if that read ever shows up on a long journal.
 */
final class WaitReason
{
    /**
     * @param Awaitable<mixed> $awaitable
     */
    public static function describe(Awaitable $awaitable, EventStoreInterface $events, string $executionId): ?string
    {
        $condition = AwaitableInspector::describeCondition($awaitable);
        if (null !== $condition) {
            return $condition;
        }

        $leaf = self::firstLeaf($awaitable);
        if (null === $leaf) {
            return null;
        }

        $reason = null;
        foreach ($events->readStream($executionId) as $event) {
            if ($leaf instanceof TimerAwaitable && $event instanceof TimerScheduled && $event->timerId() === $leaf->timerId()) {
                $reason = \sprintf(
                    'timer %sdue at %s',
                    '' === $event->summary() ? '' : '"' . $event->summary() . '" ',
                    (new \DateTimeImmutable('@' . (int) $event->scheduledAt()))->format(\DATE_ATOM),
                );
            }
            if ($leaf instanceof ActivityAwaitable && $event instanceof ActivityScheduled && $event->activityId() === $leaf->activityId()) {
                $reason = 'activity ' . $event->activityName();
            }
            if ($leaf instanceof ActivityAwaitable && $event instanceof ActivityTaskStarted && $event->activityId() === $leaf->activityId()) {
                $reason = self::attempt($event);
            }
        }

        return $reason;
    }

    /**
     * The same words as {@see describe()}, for the attempt a worker just started.
     */
    public static function attempt(ActivityTaskStarted $event): string
    {
        return \sprintf('activity %s attempt %d in flight', $event->activityName(), $event->attempt());
    }

    /**
     * @param Awaitable<mixed> $awaitable
     */
    private static function firstLeaf(Awaitable $awaitable): TimerAwaitable|ActivityAwaitable|null
    {
        if ($awaitable instanceof TimerAwaitable || $awaitable instanceof ActivityAwaitable) {
            return $awaitable;
        }
        if ($awaitable instanceof CancellingCompositeAwaitable) {
            return self::firstLeaf($awaitable->inner());
        }
        if ($awaitable instanceof CompositeAwaitable) {
            foreach ($awaitable->members() as $member) {
                $leaf = self::firstLeaf($member);
                if (null !== $leaf) {
                    return $leaf;
                }
            }
        }

        return null;
    }
}
