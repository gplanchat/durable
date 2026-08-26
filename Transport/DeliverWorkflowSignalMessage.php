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
    /** Le nom tel qu'il voyage : le message est sérialisé par Messenger, pas une enum (ADR DUR034). */
    public string $signalName;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $executionId,
        \BackedEnum|string $signalName,
        public array $payload = [],
    ) {
        $this->signalName = $signalName instanceof \BackedEnum ? (string) $signalName->value : $signalName;
    }
}
