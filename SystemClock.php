<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Psr\Clock\ClockInterface;

/**
 * The core's default clock: the wall clock, in UTC (#617).
 *
 * Every class of the core that needs "now" takes a {@see ClockInterface} and falls back on this
 * one; a host hands its own (Symfony's `clock` service), a test a frozen one.
 */
final class SystemClock implements ClockInterface
{
    public function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }
}
