<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

/**
 * @internal implémentation par défaut (bundle + tests)
 */
final class InMemoryChildWorkflowParentLinkStore implements ChildWorkflowParentLinkStoreInterface
{
    /** @var array<string, string> childExecutionId => parentExecutionId */
    private array $childToParent = [];

    public function link(string $childExecutionId, string $parentExecutionId): void
    {
        $this->childToParent[$childExecutionId] = $parentExecutionId;
    }

    public function getParentExecutionId(string $childExecutionId): ?string
    {
        return $this->childToParent[$childExecutionId] ?? null;
    }

    public function unlink(string $childExecutionId): void
    {
        unset($this->childToParent[$childExecutionId]);
    }
}
