<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The execution stopped on a requested cancellation: a **normal** termination, not a failure.
 *
 * Propagated by {@see \Gplanchat\Durable\ExecutionEngine} so that the caller stops redelivering
 * the resume (cf. {@see \Gplanchat\Durable\Handler\ResumeWorkflowHandler}).
 */
final class WorkflowCancelledException extends \RuntimeException
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $reason,
    ) {
        parent::__construct(\sprintf('Workflow %s cancelled: %s', $executionId, $reason));
    }
}
