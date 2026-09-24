<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * What a diagnostic surface may show of a payload it copies out of the journal.
 *
 * The journal holds what the workflow was given, credentials included. The profiler panel and
 * `durable:execution:diagnose --json` copy it to places with a wider audience: a profile on disk,
 * a terminal, a ticket. Implement this to replace the default key pattern.
 */
interface PayloadRedactorInterface
{
    public function redact(mixed $payload): mixed;
}
