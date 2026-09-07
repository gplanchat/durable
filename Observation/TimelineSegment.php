<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * The interval between two consecutive events of one and the same action.
 *
 * Cutting an action into segments is not decorative: as soon as the execution itself takes up a
 * row, its bar covers the whole run, and the only interesting fact — the twenty-two seconds spent
 * waiting for a worker between two of its events — would disappear into a bar that says "the run
 * lasted as long as the run".
 *
 * ⚠ **A segment that leads into a start is not work, it is a queue.** Hence `waiting`, inherited
 * from the `started` of the event that **closes** the interval: what precedes being picked up is
 * the time spent waiting for someone to be willing to begin. Two bars of the same length do not
 * tell the same story, and the operator facing a slow execution is looking for precisely which of
 * the two they are looking at — their own code, or nobody on the other end of the line.
 *
 * `failed` likewise marks the interval that **leads into** a failure — the time spent failing —
 * and not the action: an activity resumed on the second attempt carries some red and ends well.
 *
 * `title` is what a host displays when hovering the bar, composed here so that both surfaces say it
 * with the same words. A hatched band without a caption is a guessing game, and whoever hovers is
 * precisely the one who wants to know.
 *
 * `from` and `to` are the two ends, carried here rather than found back by rank in the action's
 * list of events: a host composing a tooltip needs both names, and coupling by index is exactly
 * what you re-read at three in the morning.
 */
final readonly class TimelineSegment
{
    public function __construct(
        public WorkflowRunEvent $from,
        public WorkflowRunEvent $to,
        public float $offset,
        public float $duration,
        public string $durationLabel,
        public bool $waiting,
        public bool $failed,
        public string $title,
    ) {}
}
