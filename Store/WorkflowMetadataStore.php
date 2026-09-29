<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\ExecutionId;

/**
 * Port for persisting the workflow metadata (type, payload) needed for the re-dispatch.
 *
 * After a success, the row is kept with {@see markCompleted} so that the type stays consultable
 * (profiler, observability); {@see \Gplanchat\Durable\Handler\ResumeWorkflowHandler} resumes ignore the finished rows.
 *
 * @see DUR021 Symfony Messenger integration (distributed resume)
 */
interface WorkflowMetadataStore
{
    /**
     * @param array<string, mixed> $payload
     */
    public function save(ExecutionId $executionId, string $workflowType, array $payload): void;

    /**
     * Marks the execution as successfully finished without deleting the type or the initial payload.
     */
    public function markCompleted(ExecutionId $executionId): void;

    /**
     * @return array{workflowType: string, payload: array<string, mixed>, completed?: bool}|null
     */
    public function get(ExecutionId $executionId): ?array;

    /**
     * True as long as an execution can still be resumed (suspended or running), not yet {@see markCompleted}.
     */
    public function hasActiveWorkflowMetadata(ExecutionId $executionId): bool;

    public function delete(ExecutionId $executionId): void;
}
