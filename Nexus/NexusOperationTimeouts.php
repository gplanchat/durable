<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Duration;

/**
 * The time bounds of a Nexus operation, taken together.
 *
 *     scheduled ──schedule-to-start──▶ started ──start-to-close──▶ closed
 *     └────────────────── schedule-to-close ──────────────────────┘
 *
 * Same breakdown as {@see \Gplanchat\Durable\Activity\ActivityTimeouts}, except that there is no
 * heartbeat: a Nexus operation is served by another system, which gives no intermediate sign of
 * life.
 *
 * **This object is stricter than the server, and on one precise point.** Probed (§1.3), the server
 * accepts a sub-bound larger than `schedule-to-close` and **clamps it down silently**: asking for
 * 60 s of `start-to-close` under a 10 s envelope records 10 s, with no error, no trace. The caller
 * keeps a bound it believes it has. The combination is therefore refused here, at construction,
 * where it can be read.
 *
 * An **infinite** envelope clamps nothing: on the wire it is written `0`, which Temporal reads as
 * "no bound" and not "zero seconds". {@see Duration::infinity()} says it without that disguise.
 *
 * No `executionBoundOr()` here, unlike activities: §2.2 made it conditional on the server requiring
 * a closing bound, and the probe showed that it requires none — a command with none of the three is
 * accepted, and the event records none.
 */
final readonly class NexusOperationTimeouts
{
    public function __construct(
        /** From scheduling to close: the envelope, which bounds the other two. */
        public ?Duration $scheduleToClose = null,
        /** From scheduling to the actual start at the handler. */
        public ?Duration $scheduleToStart = null,
        /** From start to close. */
        public ?Duration $startToClose = null,
    ) {
        $this->refuseSilentClamp('schedule-to-start', $scheduleToStart);
        $this->refuseSilentClamp('start-to-close', $startToClose);
    }

    /**
     * No bound at all: the server applies none either, it records the command as it stands.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * The common case: bounding the operation end to end.
     */
    public static function within(Duration $scheduleToClose): self
    {
        return new self(scheduleToClose: $scheduleToClose);
    }

    public function withScheduleToClose(?Duration $duration): self
    {
        return new self($duration, $this->scheduleToStart, $this->startToClose);
    }

    public function withScheduleToStart(?Duration $duration): self
    {
        return new self($this->scheduleToClose, $duration, $this->startToClose);
    }

    public function withStartToClose(?Duration $duration): self
    {
        return new self($this->scheduleToClose, $this->scheduleToStart, $duration);
    }

    public function areUnbounded(): bool
    {
        return null === $this->scheduleToClose
            && null === $this->scheduleToStart
            && null === $this->startToClose;
    }

    private function refuseSilentClamp(string $name, ?Duration $bound): void
    {
        $envelope = $this->scheduleToClose;
        if (null === $bound || null === $envelope || $envelope->isInfinite()) {
            return;
        }
        if (!$bound->isLongerThan($envelope)) {
            return;
        }

        throw new \InvalidArgumentException(\sprintf(
            'A %s bound of %s cannot exceed the schedule-to-close envelope of %s: the server would clamp it down to %s without an error, and the operation would end sooner than asked.',
            $name,
            $bound,
            $envelope,
            $envelope,
        ));
    }
}
