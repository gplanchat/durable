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
        $this->metadata[$executionId] = ['workflowType' => $workflowType, 'payload' => $payload];
    }

    /**
     * @return array{workflowType: string, payload: array<string, mixed>}|null
     */
    public function get(string $executionId): ?array
    {
        return $this->metadata[$executionId] ?? null;
    }

    public function delete(string $executionId): void
    {
        unset($this->metadata[$executionId]);
    }
}
