<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Stub;

use Gplanchat\Durable\ExecutionId;

/**
 * A handle on one execution of a workflow class, handed out by a
 * {@see \Gplanchat\Durable\WorkflowRepository}. It carries the id only for now.
 */
final readonly class WorkflowStub
{
    public function __construct(private ExecutionId $executionId) {}

    public function executionId(): ExecutionId
    {
        return $this->executionId;
    }
}
