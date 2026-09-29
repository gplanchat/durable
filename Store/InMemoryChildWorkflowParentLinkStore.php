<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\ExecutionId;

/**
 * @internal default implementation (bundle + tests)
 */
final class InMemoryChildWorkflowParentLinkStore implements ChildWorkflowParentLinkStoreInterface
{
    /** @var array<string, string> childExecutionId => parentExecutionId */
    private array $childToParent = [];

    public function link(ExecutionId|string $childExecutionId, ExecutionId|string $parentExecutionId): void
    {
        $childExecutionId = (string) $childExecutionId;
        $parentExecutionId = (string) $parentExecutionId;
        $this->childToParent[$childExecutionId] = $parentExecutionId;
    }

    public function getParentExecutionId(ExecutionId|string $childExecutionId): ?string
    {
        $childExecutionId = (string) $childExecutionId;

        return $this->childToParent[$childExecutionId] ?? null;
    }

    public function getChildExecutionIdsForParent(ExecutionId|string $parentExecutionId): array
    {
        $parentExecutionId = (string) $parentExecutionId;
        $children = [];
        foreach ($this->childToParent as $childExecutionId => $p) {
            if ($p === $parentExecutionId) {
                $children[] = $childExecutionId;
            }
        }

        return $children;
    }

    public function unlink(ExecutionId|string $childExecutionId): void
    {
        $childExecutionId = (string) $childExecutionId;
        unset($this->childToParent[$childExecutionId]);
    }
}
