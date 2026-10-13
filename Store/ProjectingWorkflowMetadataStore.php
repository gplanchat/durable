<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Exception\UnsupportedByBackendException;
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
final readonly class ProjectingWorkflowMetadataStore implements WorkflowMetadataStore, ReopensCompletedWorkflowMetadata
{
    public function __construct(
        private readonly WorkflowMetadataStore $inner,
        private readonly WorkflowRunProjectionInterface $projection,
    ) {}

    public function save(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        $this->inner->save($executionId, $workflowType, $payload);
        $this->projection->recordStart($executionId, $workflowType);
    }

    public function insertIfAbsent(ExecutionId $executionId, string $workflowType, array $payload): bool
    {
        $inserted = $this->inner->insertIfAbsent($executionId, $workflowType, $payload);
        if ($inserted) {
            $this->projection->recordStart($executionId, $workflowType);
        }

        return $inserted;
    }

    /**
     * The inner store must reopen too: a start that the policy allows runs on the SQL and the
     * in-memory stores, which do.
     */
    public function reopenIfCompleted(ExecutionId $executionId, string $workflowType, array $payload): bool
    {
        if (!$this->inner instanceof ReopensCompletedWorkflowMetadata) {
            throw UnsupportedByBackendException::forMethod('metadata store', 'reopenIfCompleted', 'the decorated store does not implement ReopensCompletedWorkflowMetadata');
        }
        $reopened = $this->inner->reopenIfCompleted($executionId, $workflowType, $payload);
        if ($reopened) {
            $this->projection->recordStart($executionId, $workflowType);
        }

        return $reopened;
    }

    public function markCompleted(ExecutionId $executionId): void
    {
        $this->inner->markCompleted($executionId);
    }

    public function get(ExecutionId $executionId): ?array
    {
        return $this->inner->get($executionId);
    }

    public function hasActiveWorkflowMetadata(ExecutionId $executionId): bool
    {
        return $this->inner->hasActiveWorkflowMetadata($executionId);
    }

    public function delete(ExecutionId $executionId): void
    {
        $this->inner->delete($executionId);
    }
}
