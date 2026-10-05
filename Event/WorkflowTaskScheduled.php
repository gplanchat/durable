<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

/**
 * A pass over the workflow is queued: a resume was dispatched and no worker has taken it yet.
 *
 * Mirrors the Temporal event of the same name, so a run reads the same on both backends. Carries
 * no data: the order and the recording time of the three events are the information.
 */
final readonly class WorkflowTaskScheduled implements Event
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
