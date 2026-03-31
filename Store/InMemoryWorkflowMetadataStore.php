<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

/**
 * Implémentation in-memory du WorkflowMetadataStore (tests).
 */
final class InMemoryWorkflowMetadataStore implements WorkflowMetadataStore
{
    /** @var array<string, array<string, mixed>> */
    private array $metadata = [];

    /**
     * @param array<string, mixed> $payload
     */
    public function save(string $executionId, string $workflowType, array $payload): void
    {
        $this->metadata[$executionId] = [
            'workflowType' => $workflowType,
            'payload' => $payload,
            'completed' => false,
        ];
    }

    public function markCompleted(string $executionId): void
    {
        if (!isset($this->metadata[$executionId])) {
            return;
        }
        $this->metadata[$executionId]['completed'] = true;
    }

    /**
     * @return array{workflowType: string, payload: array<string, mixed>, completed?: bool}|null
     */
    public function get(string $executionId): ?array
    {
        return $this->metadata[$executionId] ?? null;
    }

    public function hasActiveWorkflowMetadata(string $executionId): bool
    {
        $m = $this->get($executionId);
        if (null === $m) {
            return false;
        }

        return !($m['completed'] ?? false);
    }

    public function delete(string $executionId): void
    {
        unset($this->metadata[$executionId]);
    }
}
