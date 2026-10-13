<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Query;

use Gplanchat\Durable\Exception\ChildWorkflowStartDeferred;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\ChildWorkflowRunnerInterface;

/**
 * The child runner of a read-only pass: a child that the journal does not hold yet is not started,
 * and its awaitable stays unsettled.
 *
 * @internal
 */
final class UnstartedChildRunner implements ChildWorkflowRunnerInterface
{
    public function defersChildStart(): bool
    {
        return true;
    }

    /**
     * Always false: the pass cannot tell whether another parent runs a child under the same
     * explicit workflow id. A child beyond the live point, started with such an id, may therefore
     * pass here where the live pass raises {@see \Gplanchat\Durable\Exception\ChildWorkflowIdInUseException}.
     */
    public function isChildRunning(ExecutionId $childExecutionId): bool
    {
        return false;
    }

    public function runChild(ExecutionId $childExecutionId, string $workflowType, array $input, ?ExecutionId $parentExecutionId = null): mixed
    {
        throw new ChildWorkflowStartDeferred();
    }
}
