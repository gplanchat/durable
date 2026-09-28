<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port\History;

/**
 * Where the workflow's cancellation was raised inside the workflow (#325).
 *
 * `targets` are the operations withdrawn there, empty when the workflow was waiting on a condition.
 * `position` is comparable with {@see RecordedMessage::$position}, within one execution only.
 */
final readonly class CancellationDelivery
{
    /**
     * @param list<string> $targets
     */
    public function __construct(
        public int $position,
        public array $targets,
    ) {}
}
