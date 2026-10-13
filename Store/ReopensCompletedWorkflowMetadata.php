<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\ExecutionId;

/**
 * A {@see WorkflowMetadataStore} that can start a completed execution over, atomically. The client
 * port uses it for a start the id reuse policy allows after an ended execution.
 */
interface ReopensCompletedWorkflowMetadata
{
    /**
     * Replaces the type and the payload of a completed row and marks it active, in one conditional
     * step (`UPDATE ... WHERE completed = true`). Returns whether this call did: of two concurrent
     * reopens of one row, exactly one does. A running or missing row is left as it is.
     *
     * @param array<string, mixed> $payload
     */
    public function reopenIfCompleted(ExecutionId $executionId, string $workflowType, array $payload): bool;
}
