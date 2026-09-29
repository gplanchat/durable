<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\ExecutionId;

/**
 * In-memory implementation of the WorkflowMetadataStore (tests).
 */
final class InMemoryWorkflowMetadataStore implements WorkflowMetadataStore
{
    /** @var array<string, array<string, mixed>> */
    private array $metadata = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function save(ExecutionId|string $executionId, string $workflowType, array $payload): void
    {
        $executionId = (string) $executionId;
        $this->metadata[$executionId] = [
            'workflowType' => $workflowType,
            'payload' => $payload,
            'completed' => false,
        ];
    }

    public function markCompleted(ExecutionId|string $executionId): void
    {
        $executionId = (string) $executionId;
        if (!isset($this->metadata[$executionId])) {
            return;
        }
        $this->metadata[$executionId]['completed'] = true;
    }

    /**
     * @return array{workflowType: string, payload: array<string, mixed>, completed?: bool}|null
     */
    public function get(ExecutionId|string $executionId): ?array
    {
        $executionId = (string) $executionId;

        return $this->metadata[$executionId] ?? null;
    }

    public function hasActiveWorkflowMetadata(ExecutionId|string $executionId): bool
    {
        $executionId = (string) $executionId;
        $m = $this->get($executionId);
        if (null === $m) {
            return false;
        }

        return !($m['completed'] ?? false);
    }

    public function delete(ExecutionId|string $executionId): void
    {
        $executionId = (string) $executionId;
        unset($this->metadata[$executionId]);
    }
}
