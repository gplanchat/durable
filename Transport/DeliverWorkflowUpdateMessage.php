<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Messenger message: hand an update to the execution, which will process it.
 *
 * No result here: the outcome of an update is its handler's return value, and only a pass of the
 * workflow produces it — {@see \Gplanchat\Durable\Bundle\Handler\DeliverWorkflowUpdateHandler}.
 */
final readonly class DeliverWorkflowUpdateMessage
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $executionId,
        public string $updateName,
        public array $arguments = [],
    ) {}
}
