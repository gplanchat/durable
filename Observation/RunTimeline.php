<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * The history of an execution, projected into a frieze — **once, for every surface**.
 *
 * Two dashboards each derived their own: Magento placed the actions in time and told the queue
 * apart from the work, Sylius stacked blocks without position. The same run, recorded by the same
 * backend, therefore read differently depending on which application you opened — and the two
 * surfaces still to be written had no source of truth other than whichever one their author would
 * open first.
 *
 * ⚠ **This projection measures, it does not draw.** Everything is in seconds: `span`, `offset`,
 * `duration`. The Magento block it came from returned floats from 0 to 100, that is to say CSS
 * widths — the core would have started drawing for a surface that renders no markup. Scaling is the
 * host's business, and that is also where the rule that goes with it lives: a four-millisecond wait
 * must not draw wider than six milliseconds of work.
 *
 * The scale runs from the first to the last **recorded** fact, not from the start to the end of the
 * execution: a running execution has no end, and a frieze that stops at the last known fact claims
 * to know nothing more.
 *
 * @see DUR037 run observation is a projection
 * @see DUR049 one projection, several chromes: presentation is decided beside the model
 */
final readonly class RunTimeline
{
    /**
     * @param list<TimelineAction> $actions
     */
    private function __construct(
        public float $span,
        public string $spanLabel,
        public array $actions,
    ) {}

    /**
     * @param list<WorkflowRunEvent> $history
     */
    public static function of(array $history): self
    {
        if ([] === $history) {
            // A purged execution, or one never seen, is not a caller error: the host displays an
            // empty frieze without having to tell "nothing" apart from `null`.
            return new self(0.0, ReadableDuration::of(0.0), []);
        }

        $moments = [];
        $grouped = [];
        foreach ($history as $event) {
            $moments[$event->sequence] = (float) $event->recordedAt->format('U.u');
            // An event with no action is its own all by itself: its sequence is enough to tell
            // it apart, and it takes up its row like any other action.
            $grouped[$event->actionKey ?? ('#' . $event->sequence)][] = $event;
        }

        $first = min($moments);
        $span = max($moments) - $first;

        $actions = [];
        foreach ($grouped as $group) {
            $opening = $group[0];
            $closing = $group[\count($group) - 1];
            $from = $moments[$opening->sequence] - $first;
            $to = $moments[$closing->sequence] - $first;

            $actions[] = new TimelineAction(
                $opening->kind,
                // The name of the action is that of the event that opens it: it is the scheduling
                // that knows the activity's name, its follow-ups carry nothing but a number.
                $opening->label,
                $from,
                $to - $from,
                ReadableDuration::of($to - $from),
                self::segments($group, $moments, $first),
                array_map(
                    static fn(WorkflowRunEvent $event): TimelineEvent => new TimelineEvent(
                        $event,
                        $moments[$event->sequence] - $first,
                        \sprintf(
                            '#%d · %s · %s',
                            $event->sequence,
                            $event->recordedAt->format('H:i:s.v'),
                            $event->label,
                        ),
                        RecordedDetails::of($event->details),
                        $opening->label,
                    ),
                    $group,
                ),
            );
        }

        return new self($span, ReadableDuration::of($span), $actions);
    }

    /**
     * The same events, laid out in the order in which they were recorded.
     *
     * The frieze groups in order to answer "how long"; a journal lays out in order to answer "in
     * what order", and that is what an operator reads first. Rendering the second in the order of
     * the first would make the order lie. Each row keeps the name of its action, which makes it
     * possible to find in the one what you spotted in the other.
     *
     * @return list<TimelineEvent>
     */
    public function journal(): array
    {
        $rows = array_merge(...array_map(
            static fn(TimelineAction $action): array => $action->events,
            $this->actions,
        ));

        usort($rows, static fn(TimelineEvent $left, TimelineEvent $right): int => $left->event->sequence <=> $right->event->sequence);

        return $rows;
    }

    /**
     * One segment per interval between two consecutive events of the action.
     *
     * An action of a single event has no interval, therefore no segment: a lone marker already
     * says everything there is to say about an instant.
     *
     * @param list<WorkflowRunEvent> $group
     * @param array<int, float>      $moments
     *
     * @return list<TimelineSegment>
     */
    private static function segments(array $group, array $moments, float $first): array
    {
        $segments = [];
        for ($index = 1, $count = \count($group); $index < $count; ++$index) {
            $opening = $group[$index - 1];
            $closing = $group[$index];
            $from = $moments[$opening->sequence] - $first;
            $to = $moments[$closing->sequence] - $first;

            $segments[] = new TimelineSegment(
                $opening,
                $closing,
                $from,
                $to - $from,
                ReadableDuration::of($to - $from),
                // What precedes being picked up is a wait, not work.
                $closing->started,
                // Red on the interval that **leads into** the failure, not on the whole action.
                $closing->failed,
                // The kind of the interval is named: a hatched band without a caption is a
                // guessing game, and whoever hovers the bar is the one who wants to know.
                \sprintf(
                    '%s%s · #%d → #%d · %s → %s',
                    $closing->started ? 'waiting to be picked up · ' : '',
                    ReadableDuration::of($to - $from),
                    $opening->sequence,
                    $closing->sequence,
                    $opening->label,
                    $closing->label,
                ),
            );
        }

        return $segments;
    }
}
