<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * An event, and **where it sits** within the duration of its execution.
 *
 * `offset` counts the seconds since the run's first recorded fact, not since the start of the
 * action: that is what puts one row's marker on the same vertical as another's, and therefore what
 * makes it possible to read at a glance that one activity started while another was waiting.
 *
 * **Seconds**, never a percentage. Scaling requires knowing the width of a column, and a surface
 * that renders no markup has none.
 *
 * `title` is what a host displays when hovering the marker. It is composed here and not at the host
 * so that both surfaces say it with the same words: an operator moving from one to the other must
 * have nothing to translate.
 *
 * `actionLabel` is the name of the **action** the event is part of, and not its own: only the
 * scheduling knows the activity's name, its follow-ups carry nothing but a number. A table surface
 * therefore displayed `ACTIVITY TASK STARTED` on two rows out of three, where the operator was
 * looking for `charge`. It is the same string as the one that names the frieze row, so that a row
 * of the one is found again in the other. An event that is its own action all by itself names
 * itself: leaving the cell empty would look like a hole.
 *
 * `renderedDetails` is {@see RecordedDetails::of()} applied once. `null` means "nothing to
 * unfold" — and that is what lets the host leave a plain row rather than a disclosure panel that
 * opens onto nothing. The raw fact stays on `$event->details`, for a surface that serves data
 * rather than a page.
 */
final readonly class TimelineEvent
{
    public function __construct(
        public WorkflowRunEvent $event,
        public float $offset,
        public string $title,
        public ?string $renderedDetails,
        public string $actionLabel,
    ) {}
}
