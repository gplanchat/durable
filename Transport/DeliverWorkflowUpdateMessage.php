<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Message Messenger : enregistrer une mise à jour traitée ({@see \Gplanchat\Durable\Event\WorkflowUpdateHandled}) puis relancer.
 *
 * @param array<string, mixed> $arguments
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
        public mixed $result = null,
    ) {
    }
}
