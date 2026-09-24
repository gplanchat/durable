<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * The current run ends in order to chain a new run with the given payload / type.
 *
 * The history of the **new** `executionId` must be empty; the dispatch is the responsibility of
 * {@see \Gplanchat\Durable\Bundle\Handler\WorkflowRunHandler} or of the caller.
 */
final readonly class WorkflowContinuedAsNew implements Event
{
    /**
     * @param array<string, mixed> $nextPayload
     * @param array<string, mixed> $continuationMetadata Serialised {@see \Temporal\Workflow\ContinueAsNewOptions} equivalent (task_queue, timeouts, …)
     * @param ?string $newExecutionId The run that continues this one; null on a journal written before #322
     */
    public function __construct(
        private string $executionId,
        private string $nextWorkflowType,
        private array $nextPayload,
        private array $continuationMetadata = [],
        private ?string $newExecutionId = null,
    ) {}

    public function newExecutionId(): ?string
    {
        return $this->newExecutionId;
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function nextWorkflowType(): string
    {
        return $this->nextWorkflowType;
    }

    /**
     * @return array<string, mixed>
     */
    public function nextPayload(): array
    {
        return $this->nextPayload;
    }

    /**
     * @return array<string, mixed>
     */
    public function continuationMetadata(): array
    {
        return $this->continuationMetadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $p = [
            'nextWorkflowType' => $this->nextWorkflowType,
            'nextPayload' => $this->nextPayload,
        ];
        if ([] !== $this->continuationMetadata) {
            $p['continuationMetadata'] = $this->continuationMetadata;
        }
        if (null !== $this->newExecutionId) {
            $p['newExecutionId'] = $this->newExecutionId;
        }

        return $p;
    }
}
