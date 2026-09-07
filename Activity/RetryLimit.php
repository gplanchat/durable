<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

/**
 * How far we are willing to retry an activity.
 *
 * Replaces the `maxAttempts` integer where 0 meant "unlimited": a magic value every call site
 * had to translate back, and which let the comparison arithmetic scatter across the activity
 * processor and the runtime. The domain question — "is this attempt still allowed?" — is now
 * put to the object itself
 * ({@see allowsAttempt()}).
 *
 * On the wire, the representation stays the Temporal integer (`maximum_attempts`, 0 = unlimited):
 * that is the server's language, and it travels in the history of in-flight executions.
 */
final readonly class RetryLimit
{
    /** Wire value for "unlimited", aligned with `RetryPolicy.maximum_attempts`. */
    private const WIRE_UNLIMITED = 0;

    private function __construct(
        /** Total number of attempts allowed, or null when nothing bounds them. */
        private ?int $maxAttempts,
    ) {}

    /**
     * Retry with no bound on the count.
     *
     * What stops the attempts then: an exception declared non-retryable, a timeout
     * (schedule-to-start, schedule-to-close, start-to-close), or the cancellation of the
     * execution. That is the behaviour of a Temporal RetryPolicy with no `maximum_attempts`, and
     * that of an activity scheduled with no options.
     */
    public static function unlimited(): self
    {
        return new self(null);
    }

    /**
     * Bound to a **total** number of attempts, the first one included.
     */
    public static function ofAttempts(int $attempts): self
    {
        if ($attempts < 1) {
            throw new \InvalidArgumentException(\sprintf(
                'A bounded retry limit needs at least one attempt, %d given. Use RetryLimit::unlimited() for no bound.',
                $attempts,
            ));
        }

        return new self($attempts);
    }

    /**
     * Bound to a number of **retries**: the initial attempt adds to it.
     *
     * The vocabulary of the bundle ceiling (`max_activity_retries`), which counts the retries and
     * not the attempts. Zero retries there never meant "a single attempt" but
     * "no ceiling at all" — hence {@see unlimited()}.
     */
    public static function ofRetries(int $retries): self
    {
        return $retries > 0 ? self::ofAttempts($retries + 1) : self::unlimited();
    }

    /**
     * A single attempt: any failure is final.
     */
    public static function once(): self
    {
        return new self(1);
    }

    public static function fromWireValue(int $maximumAttempts): self
    {
        return self::WIRE_UNLIMITED === $maximumAttempts || $maximumAttempts < 0
            ? self::unlimited()
            : self::ofAttempts($maximumAttempts);
    }

    public function toWireValue(): int
    {
        return $this->maxAttempts ?? self::WIRE_UNLIMITED;
    }

    public function isUnlimited(): bool
    {
        return null === $this->maxAttempts;
    }

    /**
     * Total number of attempts allowed, or null if nothing bounds them.
     */
    public function maxAttempts(): ?int
    {
        return $this->maxAttempts;
    }

    /**
     * Is attempt no. {@code $attempt} (1-based) still allowed?
     */
    public function allowsAttempt(int $attempt): bool
    {
        return null === $this->maxAttempts || $attempt <= $this->maxAttempts;
    }

    /**
     * The stricter bound of the two: an activity that sets its own does not escape the
     * application's ceiling, and the other way round.
     */
    public function narrowedTo(self $other): self
    {
        if ($this->isUnlimited()) {
            return $other;
        }
        if ($other->isUnlimited()) {
            return $this;
        }

        return new self(min($this->maxAttempts, $other->maxAttempts));
    }
}
