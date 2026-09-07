<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\TaskQueue;

/**
 * Activity scheduling options (the equivalent of {@see \Temporal\Activity\ActivityOptions}).
 *
 * Three concepts, not fourteen fields: how far to retry ({@see RetryLimit}), at what pace
 * ({@see $initialInterval} / {@see $backoffCoefficient} / {@see $maximumInterval}), and within
 * which time bounds ({@see ActivityTimeouts}).
 *
 * The serialisation stays flat and in seconds: that is what the history of in-flight executions
 * carries, on the journal side as on the Temporal side.
 */
final readonly class ActivityOptions
{
    /** Temporal's default interval ceiling, expressed as a multiple of the initial interval. */
    public const DEFAULT_MAXIMUM_INTERVAL_FACTOR = 100.0;

    /** How far we are willing to retry; unlimited by default, like Temporal. */
    public RetryLimit $retryLimit;

    /** Delay before the first retry after a failure. */
    public Duration $initialInterval;

    /**
     * Ceiling on the delay between two retries. Null applies the Temporal default,
     * {@see DEFAULT_MAXIMUM_INTERVAL_FACTOR} × the initial interval — indispensable as soon as
     * attempts are unlimited, without which the exponential backoff diverges.
     */
    public ?Duration $maximumInterval;

    /** The activity's time bounds, taken together. */
    public ActivityTimeouts $timeouts;

    /**
     * @param list<class-string<\Throwable>> $nonRetryableExceptions
     */
    public function __construct(
        ?RetryLimit $retryLimit = null,
        ?Duration $initialInterval = null,
        /** Exponential backoff coefficient between retries. */
        public float $backoffCoefficient = 2.0,
        ?Duration $maximumInterval = null,
        /** Exceptions that do not trigger a retry (class-string[]). */
        public array $nonRetryableExceptions = [],
        /** Target queue (application-level routing; not used by every transport). */
        public ?TaskQueue $taskQueue = null,
        /** Business activity ID (a UUID otherwise). */
        public ?string $activityId = null,
        ?ActivityTimeouts $timeouts = null,
        public ActivityCancellationType $cancellationType = ActivityCancellationType::TryCancel,
        /** Summary for UI display (the "summary" field on the Temporal side). */
        public ?string $summary = null,
    ) {
        $this->retryLimit = $retryLimit ?? RetryLimit::unlimited();
        $this->initialInterval = $initialInterval ?? Duration::seconds(1.0);
        $this->maximumInterval = $maximumInterval;
        $this->timeouts = $timeouts ?? ActivityTimeouts::none();
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * The same object, written the way one thinks it.
     *
     * The constructor orders its parameters the way the wire serialises them; in use, the question
     * one asks first is "how many attempts, and bounded to how much time?" — two answers that
     * lived at positions 1 and 8, hence unreachable without named arguments. This factory puts
     * those two back in front and accepts the equivalent scalars:
     *
     *     ActivityOptions::of(3, 30)                    // 3 attempts, 30 s each
     *     ActivityOptions::of(5, 120, 2, [Refused::class])
     *
     * The coercions stay explicit and free of magic values: an integer is a **number of
     * attempts** ({@see RetryLimit::ofAttempts()}, which refuses 0 rather than reading
     * "unlimited" into it), a bare duration is the bound of **one** attempt
     * ({@see ActivityTimeouts::attempt()}), and a float is expressed in seconds. Nothing here the
     * constructor cannot do: all of its parameters are covered, so that an `of()` never has to be
     * rewritten as a `new` on the first need for a rare field.
     *
     * @param list<class-string<\Throwable>> $nonRetryableExceptions
     */
    public static function of(
        RetryLimit|int|null $retryLimit = null,
        ActivityTimeouts|Duration|float|null $timeouts = null,
        Duration|float|null $initialInterval = null,
        array $nonRetryableExceptions = [],
        TaskQueue|string|null $taskQueue = null,
        float $backoffCoefficient = 2.0,
        Duration|float|null $maximumInterval = null,
        ?string $summary = null,
        ?string $activityId = null,
        ActivityCancellationType $cancellationType = ActivityCancellationType::TryCancel,
    ): self {
        return new self(
            \is_int($retryLimit) ? RetryLimit::ofAttempts($retryLimit) : $retryLimit,
            self::duration($initialInterval),
            $backoffCoefficient,
            self::duration($maximumInterval),
            $nonRetryableExceptions,
            TaskQueue::fromNullable($taskQueue),
            $activityId,
            $timeouts instanceof ActivityTimeouts || null === $timeouts
                ? $timeouts
                : ActivityTimeouts::attempt(self::duration($timeouts)),
            $cancellationType,
            $summary,
        );
    }

    private static function duration(Duration|float|null $value): ?Duration
    {
        return \is_float($value) ? Duration::seconds($value) : $value;
    }

    /**
     * Delay to apply **before** attempt no. {@code $nextAttempt} (1-based), after the failure of
     * the previous attempt. Zero for the first attempt.
     */
    public function retryDelayBeforeAttempt(int $nextAttempt): Duration
    {
        if ($nextAttempt <= 1) {
            return Duration::zero();
        }

        $factor = $this->backoffCoefficient ** (float) ($nextAttempt - 2);
        // With no bound on attempts, the exponent eventually overflows the float — around the
        // thousandth attempt with the defaults. The product is capped anyway: an overflowed
        // factor means "the ceiling", not an arithmetic error.
        if (is_infinite($factor)) {
            return $this->effectiveMaximumInterval();
        }

        return $this->initialInterval
            ->multipliedBy($factor)
            ->shortest($this->effectiveMaximumInterval());
    }

    /**
     * The interval ceiling actually applied, Temporal default included.
     */
    public function effectiveMaximumInterval(): Duration
    {
        return $this->maximumInterval ?? $this->initialInterval->multipliedBy(self::DEFAULT_MAXIMUM_INTERVAL_FACTOR);
    }

    public function withRetryLimit(RetryLimit $retryLimit): self
    {
        return new self(
            $retryLimit,
            $this->initialInterval,
            $this->backoffCoefficient,
            $this->maximumInterval,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $this->activityId,
            $this->timeouts,
            $this->cancellationType,
            $this->summary,
        );
    }

    public function withTimeouts(ActivityTimeouts $timeouts): self
    {
        return new self(
            $this->retryLimit,
            $this->initialInterval,
            $this->backoffCoefficient,
            $this->maximumInterval,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $this->activityId,
            $timeouts,
            $this->cancellationType,
            $this->summary,
        );
    }

    public function isNonRetryable(\Throwable $e): bool
    {
        foreach ($this->nonRetryableExceptions as $exceptionClass) {
            if (is_a($e, $exceptionClass)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{activity_options: array<string, mixed>}
     */
    public function toMetadata(): array
    {
        $activityOptions = [
            'max_attempts' => $this->retryLimit->toWireValue(),
            'initial_interval_seconds' => $this->initialInterval->toSeconds(),
            'backoff_coefficient' => $this->backoffCoefficient,
            'non_retryable_exceptions' => $this->nonRetryableExceptions,
            'cancellation_type' => $this->cancellationType->value,
        ];
        if (null !== $this->maximumInterval) {
            $activityOptions['maximum_interval_seconds'] = $this->maximumInterval->toSeconds();
        }
        if (null !== $this->taskQueue) {
            $activityOptions['task_queue'] = $this->taskQueue->name();
        }
        if (null !== $this->activityId && '' !== $this->activityId) {
            $activityOptions['activity_id'] = $this->activityId;
        }
        $activityOptions += $this->timeouts->toMetadata();
        if (null !== $this->summary && '' !== $this->summary) {
            $activityOptions['summary'] = $this->summary;
        }

        return ['activity_options' => $activityOptions];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromMetadata(array $metadata): ?self
    {
        $opts = $metadata['activity_options'] ?? null;
        if (!\is_array($opts)) {
            return null;
        }

        $cancellation = ActivityCancellationType::TryCancel;
        if (isset($opts['cancellation_type'])) {
            $cancellation = ActivityCancellationType::tryFrom((int) $opts['cancellation_type']) ?? ActivityCancellationType::TryCancel;
        }

        return new self(
            RetryLimit::fromWireValue((int) ($opts['max_attempts'] ?? 0)),
            Duration::seconds((float) ($opts['initial_interval_seconds'] ?? 1.0)),
            (float) ($opts['backoff_coefficient'] ?? 2.0),
            Duration::fromWireValue($opts['maximum_interval_seconds'] ?? null),
            \is_array($opts['non_retryable_exceptions'] ?? null) ? $opts['non_retryable_exceptions'] : [],
            TaskQueue::fromNullable(isset($opts['task_queue']) ? (string) $opts['task_queue'] : null),
            isset($opts['activity_id']) ? (string) $opts['activity_id'] : null,
            ActivityTimeouts::fromMetadata($opts),
            $cancellation,
            isset($opts['summary']) ? (string) $opts['summary'] : null,
        );
    }
}
