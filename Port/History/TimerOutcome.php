<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port\History;

/**
 * A timer slot's recorded outcome: it fired (`failed` null), or the workflow's cancellation
 * rejected it. No deadline: the history source has none to give for every backend, and a
 * fabricated one is worse than none (C-9, #325).
 */
final readonly class TimerOutcome
{
    public function __construct(
        public string $timerId,
        public ?\Throwable $failed = null,
    ) {}
}
