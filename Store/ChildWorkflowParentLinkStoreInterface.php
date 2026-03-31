<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

/**
 * Associe temporairement un run enfant au parent pour finaliser le journal parent en mode async Messenger.
 */
interface ChildWorkflowParentLinkStoreInterface
{
    public function link(string $childExecutionId, string $parentExecutionId): void;

    public function getParentExecutionId(string $childExecutionId): ?string;

    /**
     * @return list<string> enfants enregistrés pour ce parent (ordre non garanti)
     */
    public function getChildExecutionIdsForParent(string $parentExecutionId): array;

    public function unlink(string $childExecutionId): void;
}
