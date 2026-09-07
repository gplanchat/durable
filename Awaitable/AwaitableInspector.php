<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Structural predicates on an awaitable, shared by the points that decide on the wake-up.
 */
final class AwaitableInspector
{
    private function __construct() {}

    /**
     * Does the wait bear (at least in part) on a timer?
     *
     * Must walk through the composites ({@see CompositeAwaitable}): an `any(activity, timer)`
     * does wait on a deadline, and testing it with a plain `instanceof TimerAwaitable` left the
     * execution with no wake-up scheduled — it never started again if the activity did not
     * succeed.
     *
     * @param Awaitable<mixed> $awaitable
     */
    public static function waitsOnTimer(Awaitable $awaitable): bool
    {
        if ($awaitable instanceof TimerAwaitable) {
            return true;
        }

        if ($awaitable instanceof CancellingCompositeAwaitable) {
            return self::waitsOnTimer($awaitable->inner());
        }

        if ($awaitable instanceof CompositeAwaitable) {
            foreach ($awaitable->members() as $member) {
                if (self::waitsOnTimer($member)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Names the condition the wait bears on, if there is one.
     *
     * Serves the diagnostic: an execution that no message can move forward any more must say
     * *which* of its conditions cannot become true, not merely that it is stuck.
     *
     * @param Awaitable<mixed> $awaitable
     */
    public static function describeCondition(Awaitable $awaitable): ?string
    {
        if ($awaitable instanceof ConditionAwaitable) {
            return $awaitable->describe();
        }

        if ($awaitable instanceof CancellingCompositeAwaitable) {
            return self::describeCondition($awaitable->inner());
        }

        if ($awaitable instanceof CompositeAwaitable) {
            foreach ($awaitable->members() as $member) {
                $described = self::describeCondition($member);
                if (null !== $described) {
                    return $described;
                }
            }
        }

        return null;
    }
}
