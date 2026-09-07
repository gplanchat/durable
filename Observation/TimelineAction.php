<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * One frieze row: **one action**, not a kind.
 *
 * An activity scheduled, started and then finished is one action and three events; the events of
 * the execution itself make up one, the first. Filing by kind — "activities", "signals" — forced
 * the operator to piece three rows back together by eye to work out how long *that one* had
 * lasted.
 *
 * `label` is the name of the event that **opens** the action: only the scheduling knows the
 * activity's name, its follow-ups carry nothing but a number. `kind` comes from the same source —
 * an action has the kind of whatever opens it.
 *
 * `duration` may be zero without that being an anomaly: an event that is its own action all by
 * itself has no interval, and an instant has no duration.
 */
final readonly class TimelineAction
{
    /**
     * @param list<TimelineSegment> $segments
     * @param list<TimelineEvent>   $events
     */
    public function __construct(
        public WorkflowRunEventKind $kind,
        public string $label,
        public float $offset,
        public float $duration,
        public string $durationLabel,
        public array $segments,
        public array $events,
    ) {}
}
