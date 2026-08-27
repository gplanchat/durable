<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;

/**
 * Décore le magasin de métadonnées pour semer le nom dans la projection.
 *
 * Seul `save()` est observé, et c'est délibéré : c'est le seul appel non ambigu, et le seul qui
 * porte le type de workflow. `delete()` veut dire trois choses selon le site qui l'appelle —
 * continue-as-new, annulation, échec — donc l'issue se lit dans le journal, pas ici. Le cycle de
 * vie des métadonnées n'en est pas modifié d'un iota.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/design.md
 */
final class ProjectingWorkflowMetadataStore implements WorkflowMetadataStore
{
    public function __construct(
        private readonly WorkflowMetadataStore $inner,
        private readonly WorkflowRunProjectionInterface $projection,
    ) {}

    public function save(string $executionId, string $workflowType, array $payload): void
    {
        $this->inner->save($executionId, $workflowType, $payload);
        $this->projection->recordStart($executionId, $workflowType);
    }

    public function markCompleted(string $executionId): void
    {
        $this->inner->markCompleted($executionId);
    }

    public function get(string $executionId): ?array
    {
        return $this->inner->get($executionId);
    }

    public function hasActiveWorkflowMetadata(string $executionId): bool
    {
        return $this->inner->hasActiveWorkflowMetadata($executionId);
    }

    public function delete(string $executionId): void
    {
        $this->inner->delete($executionId);
    }
}
