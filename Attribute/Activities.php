<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

use Gplanchat\Durable\Activity\ActivityCancellationType;
use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\ActivityTimeouts;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\TaskQueue;

/**
 * Names the activity contract of an `ActivityStub` parameter of a workflow method, and optionally
 * the options its activities are scheduled with.
 *
 * ```php
 * #[AsWorkflowMethod]
 * public function run(
 *     string $name,
 *     #[Activities(GreetingActivities::class, attempts: 3, startToClose: 120.0)] ActivityStub $greeting,
 *     WorkflowEnvironment $env,
 * ): string
 * ```
 *
 * The loader reads it once, when the workflow is registered, and hands the method
 * `$env->activityStub(GreetingActivities::class, $options)`. PHP has no runtime generics, so the
 * attribute is what carries the contract; the `@param ActivityStub<GreetingActivities>` docblock is
 * only there for PHPStan.
 *
 * An attribute argument is a constant expression — scalars, arrays, enum cases, never a call — so
 * the options come as scalars (durations in seconds) and {@see options()} builds the value
 * objects. Every option left out keeps the {@see ActivityOptions} default.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final class Activities
{
    /**
     * @param list<string> $nonRetryable exception classes; checked in {@see options()}, since an
     *                                   attribute argument is checked by nobody else
     */
    public function __construct(
        public readonly string $contract,
        /** Attempts in all, the first included; null is unlimited. */
        public readonly ?int $attempts = null,
        /** Seconds granted to one attempt. */
        public readonly ?float $startToClose = null,
        /** Seconds from scheduling to close, retries included. */
        public readonly ?float $scheduleToClose = null,
        /** Seconds an activity may wait in its queue before a worker starts it. */
        public readonly ?float $scheduleToStart = null,
        /** Longest silence, in seconds, between two heartbeats. */
        public readonly ?float $heartbeat = null,
        /** Seconds before the first retry. */
        public readonly ?float $initialInterval = null,
        public readonly ?float $backoffCoefficient = null,
        /** Ceiling, in seconds, on the delay between two retries. */
        public readonly ?float $maximumInterval = null,
        public readonly array $nonRetryable = [],
        public readonly ?string $taskQueue = null,
        public readonly ?ActivityCancellationType $cancellationType = null,
        public readonly ?string $summary = null,
    ) {}

    /**
     * The options the stub is built with, or null when none is set: the stub then keeps exactly
     * the defaults it had before this attribute took options.
     *
     * @throws \InvalidArgumentException naming the parameter whose value is impossible
     */
    public function options(): ?ActivityOptions
    {
        $durations = [
            'startToClose' => $this->startToClose,
            'scheduleToClose' => $this->scheduleToClose,
            'scheduleToStart' => $this->scheduleToStart,
            'heartbeat' => $this->heartbeat,
            'initialInterval' => $this->initialInterval,
            'maximumInterval' => $this->maximumInterval,
        ];
        if (null === $this->attempts && null === $this->backoffCoefficient && [] === $this->nonRetryable
            && null === $this->taskQueue && null === $this->cancellationType && null === $this->summary
            && [] === array_filter($durations, static fn(?float $d): bool => null !== $d)) {
            return null;
        }

        $seconds = [];
        foreach ($durations as $parameter => $value) {
            $seconds[$parameter] = null === $value ? null : self::named($parameter, static fn(): Duration => Duration::seconds($value));
        }
        $nonRetryable = [];
        foreach ($this->nonRetryable as $class) {
            if (!is_a($class, \Throwable::class, true)) {
                throw new \InvalidArgumentException(\sprintf('#[Activities] nonRetryable: %s is not a \Throwable class.', $class));
            }
            $nonRetryable[] = $class;
        }

        return new ActivityOptions(
            retryLimit: null === $this->attempts ? null : self::named('attempts', fn(): RetryLimit => RetryLimit::ofAttempts($this->attempts)),
            initialInterval: $seconds['initialInterval'],
            backoffCoefficient: $this->backoffCoefficient ?? 2.0,
            maximumInterval: $seconds['maximumInterval'],
            nonRetryableExceptions: $nonRetryable,
            taskQueue: null === $this->taskQueue ? null : self::named('taskQueue', fn(): TaskQueue => TaskQueue::from($this->taskQueue)),
            timeouts: self::named('heartbeat', static fn(): ActivityTimeouts => new ActivityTimeouts(
                scheduleToStart: $seconds['scheduleToStart'],
                startToClose: $seconds['startToClose'],
                scheduleToClose: $seconds['scheduleToClose'],
                heartbeat: $seconds['heartbeat'],
            )),
            cancellationType: $this->cancellationType ?? ActivityCancellationType::TryCancel,
            summary: $this->summary,
        );
    }

    /**
     * @template T
     *
     * @param \Closure(): T $build
     *
     * @return T
     */
    private static function named(string $parameter, \Closure $build): mixed
    {
        try {
            return $build();
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException(\sprintf('#[Activities] %s: %s', $parameter, $e->getMessage()), 0, $e);
        }
    }
}
