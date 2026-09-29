<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\ExecutionId;

/**
 * Temporarily links a child run to its parent, to finalise the parent journal in Messenger async mode.
 */
interface ChildWorkflowParentLinkStoreInterface
{
    public function link(ExecutionId|string $childExecutionId, ExecutionId|string $parentExecutionId): void;

    public function getParentExecutionId(ExecutionId|string $childExecutionId): ?string;

    /**
     * @return list<string> children recorded for this parent (order not guaranteed)
     */
    public function getChildExecutionIdsForParent(ExecutionId|string $parentExecutionId): array;

    public function unlink(ExecutionId|string $childExecutionId): void;
}
