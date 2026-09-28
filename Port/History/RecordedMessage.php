<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port\History;

/**
 * A signal or an update, as recorded in an execution's history (#325).
 *
 * `position` is the rank of the event in this execution's own history — the stream index in
 * memory, the `eventId` on Temporal. It orders messages against a deadline within one execution,
 * and is never compared across backends (DUR035).
 */
final readonly class RecordedMessage
{
    /**
     * @param 'signal'|'update'    $kind
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public int $position,
        public string $kind,
        public string $name,
        public array $payload,
    ) {}
}
