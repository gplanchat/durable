<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

/**
 * Port for persisting the workflow metadata (type, payload) needed for the re-dispatch.
 *
 * After a success, the row is kept with {@see markCompleted} so that the type stays consultable
 * (profiler, observability); {@see WorkflowRunHandler} resumes ignore the finished rows.
 *
 * @see DUR021 Symfony Messenger integration (distributed resume)
 */
interface WorkflowMetadataStore
{
    /**
     * @param array<string, mixed> $payload
     */
    public function save(string $executionId, string $workflowType, array $payload): void;

    /**
     * Marks the execution as successfully finished without deleting the type or the initial payload.
     */
    public function markCompleted(string $executionId): void;

    /**
     * @return array{workflowType: string, payload: array<string, mixed>, completed?: bool}|null
     */
    public function get(string $executionId): ?array;

    /**
     * True as long as an execution can still be resumed (suspended or running), not yet {@see markCompleted}.
     */
    public function hasActiveWorkflowMetadata(string $executionId): bool;

    public function delete(string $executionId): void;
}
