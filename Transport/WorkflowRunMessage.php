<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Message Messenger pour (re)démarrer ou reprendre un workflow.
 *
 * @see ADR009 Modèle distribué et re-dispatch
 */
final readonly class WorkflowRunMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $executionId,
        public string $workflowType = '',
        public array $payload = [],
    ) {
    }

    public function isResume(): bool
    {
        return '' === $this->workflowType && [] === $this->payload;
    }
}
