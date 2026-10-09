<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

/**
 * A worker took the queued pass. The interval since WorkflowTaskScheduled is the wait for a worker.
 *
 * Mirrors the Temporal event of the same name, so a run reads the same on both backends. Carries
 * no data: the order and the recording time of the three events are the information.
 */
final readonly class WorkflowTaskStarted implements Event
{
    public function __construct(
        private ExecutionId $executionId,
    ) {}

    public function executionId(): ExecutionId
    {
        return $this->executionId;
    }

    public function payload(): array
    {
        return [];
    }
}
