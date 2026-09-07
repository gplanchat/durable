<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * A length of time.
 *
 * Lives at the root of the domain: activities, workflows and timers all bound time, and they
 * must speak about it the same way.
 *
 * Replaces the `?float …Seconds`: the unit lived in the field name, never in the type, and
 * every reader had to say `null !== $x && $x > 0` again before using it. The comparisons of the
 * domain — "has this delay elapsed?", "which one is the shorter?" — are now asked of the
 * object.
 *
 * On the wire the representation stays a number of seconds: that is what the history of the
 * executions currently running already carries.
 */
final readonly class Duration
{
    private function __construct(
        private float $seconds,
    ) {}

    public static function seconds(float $seconds): self
    {
        if ($seconds < 0.0) {
            throw new \InvalidArgumentException(\sprintf('A duration cannot be negative, %.3fs given.', $seconds));
        }
        if (is_infinite($seconds) || is_nan($seconds)) {
            // A computed INF is an arithmetic mistake, not an intent: infinity is asked for
            // by its name, {@see infinity()}, and never by accident.
            throw new \InvalidArgumentException('A duration must be a finite number of seconds. Use Duration::infinity() to say "no bound at all".');
        }

        return new self($seconds);
    }

    public static function milliseconds(float $milliseconds): self
    {
        return self::seconds($milliseconds / 1_000.0);
    }

    public static function minutes(float $minutes): self
    {
        return self::seconds($minutes * 60.0);
    }

    public static function hours(float $hours): self
    {
        return self::seconds($hours * 3_600.0);
    }

    /**
     * From a native interval.
     *
     * Covers Carbon without depending on it: `CarbonInterval` extends `DateInterval`. Calendar
     * units (years, months) have no fixed length; they are resolved against a fixed UTC anchor,
     * so they are approximate — prefer days/hours/minutes for a time bound.
     */
    public static function of(\DateInterval $interval): self
    {
        $anchor = new \DateTimeImmutable('@0');
        $shifted = $anchor->add($interval);

        return self::seconds(
            ((float) $shifted->format('U.u')) - ((float) $anchor->format('U.u')),
        );
    }

    /**
     * From here (or from a given instant) until a due time.
     *
     * A `DateTimeInterface` is an **instant**, not a length: `Carbon` included, it only becomes
     * a duration once related to another instant. That is why the method is not called `of()`.
     */
    public static function until(\DateTimeInterface $deadline, ?\DateTimeInterface $from = null): self
    {
        $from ??= new \DateTimeImmutable();

        return self::seconds(((float) $deadline->format('U.u')) - ((float) $from->format('U.u')));
    }

    /**
     * Boundary coercion: accepts whatever the caller has at hand.
     *
     * A number is read as seconds, a {@see \DateInterval} (hence a `CarbonInterval`) as a
     * length, a {@see \DateTimeInterface} (hence a `Carbon`) as a due time counted from now.
     */
    public static function from(self|\DateInterval|\DateTimeInterface|int|float $value): self
    {
        return match (true) {
            $value instanceof self => $value,
            $value instanceof \DateInterval => self::of($value),
            $value instanceof \DateTimeInterface => self::until($value),
            default => self::seconds((float) $value),
        };
    }

    /**
     * No wait at all. Distinct from "no bound", which is said with {@see infinity()}.
     */
    public static function zero(): self
    {
        return new self(0.0);
    }

    /**
     * However long it takes.
     *
     * The absence of a bound used to be a `null`: the absence of a value, not a value. It did
     * not compare ({@see shortest()}), did not travel through a configuration, and forced every
     * site that accepts a deadline to write its own special case. It is nonetheless a perfectly
     * well-defined length of time in the domain — that of a wait one does not bound — and it
     * deserves to be said as such.
     *
     * An infinite duration is not a wire duration: {@see WorkflowEnvironment::timer()} refuses
     * it, because a timer that never fires is a wake-up that does not exist.
     */
    public static function infinity(): self
    {
        return new self(\INF);
    }

    /**
     * Wire decoding: a value that is absent, zero or negative means "no bound".
     *
     * That is the Temporal convention, where a timeout of 0 stands for "not set".
     */
    public static function fromWireValue(mixed $seconds): ?self
    {
        if (!is_numeric($seconds)) {
            return null;
        }
        $value = (float) $seconds;

        return $value > 0.0 ? new self($value) : null;
    }

    public function toSeconds(): float
    {
        return $this->seconds;
    }

    public function isZero(): bool
    {
        return 0.0 === $this->seconds;
    }

    /**
     * This duration never elapses: nothing bounds the wait it measures.
     */
    public function isInfinite(): bool
    {
        return is_infinite($this->seconds);
    }

    public function isLongerThan(self $other): bool
    {
        return $this->seconds > $other->seconds;
    }

    public function shortest(self $other): self
    {
        return $this->seconds <= $other->seconds ? $this : $other;
    }

    public function multipliedBy(float $factor): self
    {
        return self::seconds($this->seconds * $factor);
    }

    /**
     * Has this duration elapsed since the given instant?
     *
     * Both instants are floating-point timestamps ({@see microtime()}), as everywhere the
     * engine measures how long an activity has been waiting.
     */
    public function hasElapsedSince(float $startedAt, float $now): bool
    {
        return ($now - $startedAt) > $this->seconds;
    }

    /**
     * Back to a native interval, to the microsecond.
     */
    public function toDateInterval(): \DateInterval
    {
        $anchor = new \DateTimeImmutable('@0');

        return $anchor->diff($anchor->modify(\sprintf('+%d microseconds', (int) round($this->seconds * 1_000_000.0))));
    }

    public function __toString(): string
    {
        return \sprintf('%.3fs', $this->seconds);
    }
}
