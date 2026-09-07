<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;

/**
 * Decorates the metadata store to seed the name into the projection.
 *
 * Only `save()` is observed, and that is deliberate: it is the only unambiguous call, and the only
 * one that carries the workflow type. `delete()` means three things depending on the site that
 * calls it — continue-as-new, cancellation, failure — so the outcome is read from the journal, not
 * here. The metadata lifecycle is not changed by one iota.
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
