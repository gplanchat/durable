<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Timer;

use Psr\Clock\ClockInterface;

/**
 * The in-memory runner's time: it moves only when told to (#617).
 *
 * It jumps to the next timer when nothing else can progress, and it moves by the real time the
 * drain spends waiting out a retry's backoff — so that wait counts towards schedule-to-close.
 * It never follows the wall clock on its own: a short timer must not win a race against an
 * activity that is still running.
 *
 * @internal
 */
final class VirtualClock implements ClockInterface
{
    public function __construct(
        private float $seconds,
    ) {}

    public function now(): \DateTimeImmutable
    {
        $now = \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $this->seconds), new \DateTimeZone('UTC'));
        \assert(false !== $now);

        return $now;
    }

    public function seconds(): float
    {
        return $this->seconds;
    }

    public function advance(float $seconds): void
    {
        $this->seconds += max(0.0, $seconds);
    }
}
