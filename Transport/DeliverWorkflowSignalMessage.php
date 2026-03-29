<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Message Messenger : déposer un signal dans le journal puis relancer le workflow.
 *
 * @see \Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler
 */
final readonly class DeliverWorkflowSignalMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $executionId,
        public string $signalName,
        public array $payload = [],
    ) {
    }
}
