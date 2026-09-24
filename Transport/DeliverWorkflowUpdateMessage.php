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
     * One id per logical update, drawn here and serialized with the message: a redelivery carries
     * the same one, and the cluster answers it with the first outcome instead of running it again.
     */
    public string $updateId;

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $executionId,
        public string $updateName,
        public array $arguments = [],
        ?string $updateId = null,
    ) {
        $this->updateId = $updateId ?? bin2hex(random_bytes(16));
    }
}
