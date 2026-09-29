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

    public function link(ExecutionId $childExecutionId, ExecutionId $parentExecutionId): void
    {
        $this->childToParent[$childExecutionId->toString()] = $parentExecutionId->toString();
    }

    public function getParentExecutionId(ExecutionId $childExecutionId): ?ExecutionId
    {
        $parent = $this->childToParent[$childExecutionId->toString()] ?? null;

        return null === $parent ? null : ExecutionId::fromString($parent);
    }

    public function getChildExecutionIdsForParent(ExecutionId $parentExecutionId): array
    {
        $children = [];
        foreach ($this->childToParent as $child => $parent) {
            if ($parent === $parentExecutionId->toString()) {
                $children[] = ExecutionId::fromString((string) $child);
            }
        }

        return $children;
    }

    public function unlink(ExecutionId $childExecutionId): void
    {
        unset($this->childToParent[$childExecutionId->toString()]);
    }
}
