<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Message Messenger : exécuter {@see \Gplanchat\Durable\ExecutionRuntime::checkTimers()} pour un run,
 * puis relancer le workflow si au moins un timer est passé à complété.
 */
final readonly class FireWorkflowTimersMessage
{
    public function __construct(
        public string $executionId,
    ) {
    }
}
