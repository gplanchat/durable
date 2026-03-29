<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Options pour {@see ExecutionContext::continueAsNew()} (équivalent {@see \Temporal\Workflow\ContinueAsNewOptions}).
 */
final readonly class ContinueAsNewOptions
{
    public function __construct(
        public ?string $taskQueue = null,
        public ?float $workflowRunTimeoutSeconds = null,
        public ?float $workflowTaskTimeoutSeconds = null,
    ) {
    }

    public static function new(): self
    {
        return new self();
    }

    public function withTaskQueue(?string $taskQueue): self
    {
        return new self($taskQueue, $this->workflowRunTimeoutSeconds, $this->workflowTaskTimeoutSeconds);
    }

    public function withWorkflowRunTimeoutSeconds(?float $seconds): self
    {
        return new self($this->taskQueue, $seconds, $this->workflowTaskTimeoutSeconds);
    }

    public function withWorkflowTaskTimeoutSeconds(?float $seconds): self
    {
        return new self($this->taskQueue, $this->workflowRunTimeoutSeconds, $seconds);
    }

    /**
     * @return array<string, mixed>
     */
    public function toMetadata(): array
    {
        $m = [];
        if (null !== $this->taskQueue && '' !== $this->taskQueue) {
            $m['task_queue'] = $this->taskQueue;
        }
        if (null !== $this->workflowRunTimeoutSeconds) {
            $m['workflow_run_timeout_seconds'] = $this->workflowRunTimeoutSeconds;
        }
        if (null !== $this->workflowTaskTimeoutSeconds) {
            $m['workflow_task_timeout_seconds'] = $this->workflowTaskTimeoutSeconds;
        }

        return $m;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromMetadata(array $metadata): self
    {
        return new self(
            isset($metadata['task_queue']) ? (string) $metadata['task_queue'] : null,
            isset($metadata['workflow_run_timeout_seconds']) ? (float) $metadata['workflow_run_timeout_seconds'] : null,
            isset($metadata['workflow_task_timeout_seconds']) ? (float) $metadata['workflow_task_timeout_seconds'] : null,
        );
    }
}
