<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\ExecutionId;
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

    public function save(ExecutionId|string $executionId, string $workflowType, array $payload): void
    {
        $executionId = (string) $executionId;
        $this->inner->save($executionId, $workflowType, $payload);
        $this->projection->recordStart($executionId, $workflowType);
    }

    public function markCompleted(ExecutionId|string $executionId): void
    {
        $executionId = (string) $executionId;
        $this->inner->markCompleted($executionId);
    }

    public function get(ExecutionId|string $executionId): ?array
    {
        $executionId = (string) $executionId;

        return $this->inner->get($executionId);
    }

    public function hasActiveWorkflowMetadata(ExecutionId|string $executionId): bool
    {
        $executionId = (string) $executionId;

        return $this->inner->hasActiveWorkflowMetadata($executionId);
    }

    public function delete(ExecutionId|string $executionId): void
    {
        $executionId = (string) $executionId;
        $this->inner->delete($executionId);
    }
}
