<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * A run's history told as one line per event, ending with what the run waits on or how it ended.
 *
 * The last line comes from the run description, not from the journal: the label of
 * `await(fn, label: 'signal approve')` is recorded in the run catalog at each suspension (#324),
 * so the story needs no new fact in the journal. A condition without a label reads
 * `condition at file:line`: the engine cannot tell which signal a closure waits for.
 *
 * Text only, no payloads: a payload goes through {@see PayloadRedactorInterface} where it is shown.
 */
final class RunNarrator
{
    private function __construct() {}

    /**
     * @param list<WorkflowRunEvent> $history
     *
     * @return list<string>
     */
    public static function story(WorkflowRunDescription $run, array $history): array
    {
        $lines = [];
        foreach ($history as $event) {
            $lines[] = \sprintf(
                '%s  %-9s %s%s%s',
                $event->recordedAt->format('H:i:s'),
                $event->kind->value,
                $event->label,
                null === $event->phase ? '' : ' ' . $event->phase->value,
                null === $event->attempt ? '' : \sprintf(' (attempt %d)', $event->attempt),
            );
        }

        $lines[] = $run->status->isRunning()
            ? 'Waiting on: ' . ($run->waitingOn ?? 'nothing recorded (a child workflow, a Nexus operation, or no suspension yet)')
            : 'Ended: ' . $run->status->value;

        return $lines;
    }
}
