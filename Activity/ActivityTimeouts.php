<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

use Gplanchat\Durable\Duration;

/**
 * The time bounds of an activity, taken together.
 *
 * They do not read in isolation — each one bounds a different segment of an activity's life, and
 * it is their composition that means something:
 *
 *     scheduled ──schedule-to-start──▶ started ──start-to-close──▶ closed
 *     └─────────────────────── schedule-to-close ───────────────────────┘
 *                              heartbeat: maximum silence during execution
 *
 * The four fields were four independent `?float` in {@see ActivityOptions}, which every reader
 * retested one by one. Grouping them also makes it possible to name the server's rule:
 * an activity must have a closing bound ({@see executionBoundOr()}).
 */
final readonly class ActivityTimeouts
{
    public function __construct(
        /** From scheduling to the actual start: how long to accept waiting in the queue. */
        public ?Duration $scheduleToStart = null,
        /** From start to close: how much time to grant **one** attempt. */
        public ?Duration $startToClose = null,
        /** From scheduling to close, retries included: the end-to-end bound. */
        public ?Duration $scheduleToClose = null,
        /** Longest silence tolerated during execution, for activities that send heartbeats. */
        public ?Duration $heartbeat = null,
    ) {
        if (null !== $heartbeat && null !== $startToClose && $heartbeat->isLongerThan($startToClose)) {
            throw new \InvalidArgumentException(\sprintf(
                'Heartbeat timeout (%s) cannot exceed start-to-close (%s): the attempt would end before the first missed heartbeat.',
                $heartbeat,
                $startToClose,
            ));
        }
    }

    /**
     * No bound at all: the backend applies its defaults.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * The common case: bounding one attempt.
     */
    public static function attempt(Duration $startToClose): self
    {
        return new self(startToClose: $startToClose);
    }

    public function withScheduleToStart(?Duration $duration): self
    {
        return new self($duration, $this->startToClose, $this->scheduleToClose, $this->heartbeat);
    }

    public function withStartToClose(?Duration $duration): self
    {
        return new self($this->scheduleToStart, $duration, $this->scheduleToClose, $this->heartbeat);
    }

    public function withScheduleToClose(?Duration $duration): self
    {
        return new self($this->scheduleToStart, $this->startToClose, $duration, $this->heartbeat);
    }

    public function withHeartbeat(?Duration $duration): self
    {
        return new self($this->scheduleToStart, $this->startToClose, $this->scheduleToClose, $duration);
    }

    /**
     * The bound of **one** execution, or the fallback given.
     *
     * Temporal refuses an activity with no closing bound: the bridge has to produce one. This
     * method names that fallback, instead of leaving it as a `?: 30.0` in the middle of building
     * a command.
     */
    public function executionBoundOr(Duration $fallback): Duration
    {
        return $this->startToClose ?? $this->scheduleToClose ?? $fallback;
    }

    /**
     * True if no bound is set.
     */
    public function areUnbounded(): bool
    {
        return null === $this->scheduleToStart
            && null === $this->startToClose
            && null === $this->scheduleToClose
            && null === $this->heartbeat;
    }

    /**
     * @return array<string, float>
     */
    public function toMetadata(): array
    {
        $m = [];
        foreach ([
            'schedule_to_start_timeout_seconds' => $this->scheduleToStart,
            'start_to_close_timeout_seconds' => $this->startToClose,
            'schedule_to_close_timeout_seconds' => $this->scheduleToClose,
            'heartbeat_timeout_seconds' => $this->heartbeat,
        ] as $key => $duration) {
            if (null !== $duration) {
                $m[$key] = $duration->toSeconds();
            }
        }

        return $m;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromMetadata(array $metadata): self
    {
        return new self(
            Duration::fromWireValue($metadata['schedule_to_start_timeout_seconds'] ?? null),
            Duration::fromWireValue($metadata['start_to_close_timeout_seconds'] ?? null),
            Duration::fromWireValue($metadata['schedule_to_close_timeout_seconds'] ?? null),
            Duration::fromWireValue($metadata['heartbeat_timeout_seconds'] ?? null),
        );
    }
}
