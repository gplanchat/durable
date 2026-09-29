<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\ExecutionId;

/**
 * Temporarily links a child run to its parent, to finalise the parent journal in Messenger async mode.
 */
interface ChildWorkflowParentLinkStoreInterface
{
    public function link(ExecutionId $childExecutionId, ExecutionId $parentExecutionId): void;

    public function getParentExecutionId(ExecutionId $childExecutionId): ?ExecutionId;

    /**
     * @return list<ExecutionId> children recorded for this parent (order not guaranteed)
     */
    public function getChildExecutionIdsForParent(ExecutionId $parentExecutionId): array;

    public function unlink(ExecutionId $childExecutionId): void;
}
