<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ParentClosePolicy;

/**
 * A child workflow is scheduled from the parent (journal of the **parent**).
 */
final readonly class ChildWorkflowScheduled implements Event
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $schedulingMetadata Temporal-aligned options (namespace, task_queue, timeouts, …)
     */
    public function __construct(
        private ExecutionId $parentExecutionId,
        private ExecutionId|string $childExecutionId,
        private string $childWorkflowType,
        private array $input,
        private ParentClosePolicy $parentClosePolicy = ParentClosePolicy::Terminate,
        private ?string $requestedWorkflowId = null,
        private array $schedulingMetadata = [],
    ) {}

    public function executionId(): ExecutionId
    {
        return $this->parentExecutionId;
    }

    public function childExecutionId(): string
    {
        return (string) $this->childExecutionId;
    }

    public function childWorkflowType(): string
    {
        return $this->childWorkflowType;
    }

    /**
     * @return array<string, mixed>
     */
    public function input(): array
    {
        return $this->input;
    }

    public function parentClosePolicy(): ParentClosePolicy
    {
        return $this->parentClosePolicy;
    }

    /**
     * Id asked for by the caller (if different from {@see childExecutionId()} when auto-generated).
     */
    public function requestedWorkflowId(): ?string
    {
        return $this->requestedWorkflowId;
    }

    /**
     * @return array<string, mixed>
     */
    public function schedulingMetadata(): array
    {
        return $this->schedulingMetadata;
    }

    public function payload(): array
    {
        $p = [
            'childExecutionId' => (string) $this->childExecutionId,
            'childWorkflowType' => $this->childWorkflowType,
            'input' => $this->input,
            'parentClosePolicy' => $this->parentClosePolicy->value,
            'requestedWorkflowId' => $this->requestedWorkflowId,
        ];
        if ([] !== $this->schedulingMetadata) {
            $p['schedulingMetadata'] = $this->schedulingMetadata;
        }

        return $p;
    }
}
