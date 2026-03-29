<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Fin réussie d’un workflow enfant (journal du **parent**).
 */
final readonly class ChildWorkflowCompleted implements Event
{
    public function __construct(
        private string $parentExecutionId,
        private string $childExecutionId,
        private mixed $result,
    ) {
    }

    public function executionId(): string
    {
        return $this->parentExecutionId;
    }

    public function childExecutionId(): string
    {
        return $this->childExecutionId;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function payload(): array
    {
        return [
            'childExecutionId' => $this->childExecutionId,
            'result' => $this->result,
        ];
    }
}
