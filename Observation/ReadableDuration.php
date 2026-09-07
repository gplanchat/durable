<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * A duration, said the way an operator reads it.
 *
 * The threshold above which a second is worth more than a millisecond is a decision, not a template
 * detail: if every surface takes it upon itself, the same execution reads "2.0 s" on one and
 * "2004 ms" on the other, and the operator moving from Magento to Sylius has to convert in their
 * head. So it is taken once, next to the observation model whose facts it describes.
 *
 * This is formatting inside the core, and it is deliberate: `WorkflowRunEvent::$label` already is
 * some, for the same reason — what several hosts must say the same way is decided in a single
 * place.
 */
final class ReadableDuration
{
    public static function of(float $seconds): string
    {
        return match (true) {
            $seconds < 1.0 => \sprintf('%d ms', (int) round($seconds * 1000.0)),
            $seconds < 90.0 => \sprintf('%.1f s', $seconds),
            default => \sprintf('%d min %02d s', (int) ($seconds / 60.0), (int) fmod($seconds, 60.0)),
        };
    }
}
