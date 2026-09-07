<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Messenger message: run {@see \Gplanchat\Durable\ExecutionRuntime::checkTimers()} for a run,
 * then resume the workflow if at least one timer has moved to completed.
 */
final readonly class FireWorkflowTimersMessage
{
    public function __construct(
        public string $executionId,
    ) {}
}
