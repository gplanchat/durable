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
    public function save(ExecutionId $executionId, string $workflowType, array $payload): void
    {
        $this->metadata[$executionId->toString()] = [
            'workflowType' => $workflowType,
            'payload' => $payload,
            'completed' => false,
        ];
    }

    public function markCompleted(ExecutionId $executionId): void
    {
        if (!isset($this->metadata[$executionId->toString()])) {
            return;
        }
        $this->metadata[$executionId->toString()]['completed'] = true;
    }

    /**
     * @return array{workflowType: string, payload: array<string, mixed>, completed?: bool}|null
     */
    public function get(ExecutionId $executionId): ?array
    {
        return $this->metadata[$executionId->toString()] ?? null;
    }

    public function hasActiveWorkflowMetadata(ExecutionId $executionId): bool
    {
        $m = $this->get($executionId);
        if (null === $m) {
            return false;
        }

        return !($m['completed'] ?? false);
    }

    public function delete(ExecutionId $executionId): void
    {
        unset($this->metadata[$executionId->toString()]);
    }
}
