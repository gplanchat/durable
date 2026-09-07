<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

/**
 * Temporarily links a child run to its parent, to finalise the parent journal in Messenger async mode.
 */
interface ChildWorkflowParentLinkStoreInterface
{
    public function link(string $childExecutionId, string $parentExecutionId): void;

    public function getParentExecutionId(string $childExecutionId): ?string;

    /**
     * @return list<string> children recorded for this parent (order not guaranteed)
     */
    public function getChildExecutionIdsForParent(string $parentExecutionId): array;

    public function unlink(string $childExecutionId): void;
}
