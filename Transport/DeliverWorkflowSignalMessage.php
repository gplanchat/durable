<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Messenger message: drop a signal into the journal, then resume the workflow.
 *
 * @see \Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler
 */
final readonly class DeliverWorkflowSignalMessage
{
    /** The name as it travels: the message is serialized by Messenger, not an enum (ADR DUR034). */
    public string $signalName;

    /**
     * One id per logical signal, drawn here and serialized with the message: a redelivery carries
     * the same one, and the cluster drops the duplicate.
     */
    public string $requestId;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $executionId,
        \BackedEnum|string $signalName,
        public array $payload = [],
        ?string $requestId = null,
    ) {
        $this->signalName = $signalName instanceof \BackedEnum ? (string) $signalName->value : $signalName;
        $this->requestId = $requestId ?? bin2hex(random_bytes(16));
    }
}
