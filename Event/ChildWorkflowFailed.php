<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Échec d’un workflow enfant (journal du **parent**).
 */
final readonly class ChildWorkflowFailed implements Event
{
    /**
     * @param array<string, mixed> $workflowFailureContext
     */
    public function __construct(
        private string $parentExecutionId,
        private string $childExecutionId,
        private string $failureMessage,
        private int $failureCode = 0,
        private ?string $workflowFailureKind = null,
        private ?string $workflowFailureClass = null,
        private array $workflowFailureContext = [],
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

    public function failureMessage(): string
    {
        return $this->failureMessage;
    }

    public function failureCode(): int
    {
        return $this->failureCode;
    }

    public function workflowFailureKind(): ?string
    {
        return $this->workflowFailureKind;
    }

    public function workflowFailureClass(): ?string
    {
        return $this->workflowFailureClass;
    }

    /**
     * @return array<string, mixed>
     */
    public function workflowFailureContext(): array
    {
        return $this->workflowFailureContext;
    }

    public function payload(): array
    {
        $p = [
            'childExecutionId' => $this->childExecutionId,
            'failureMessage' => $this->failureMessage,
            'failureCode' => $this->failureCode,
        ];
        if (null !== $this->workflowFailureKind) {
            $p['workflowFailureKind'] = $this->workflowFailureKind;
        }
        if (null !== $this->workflowFailureClass) {
            $p['workflowFailureClass'] = $this->workflowFailureClass;
        }
        if ([] !== $this->workflowFailureContext) {
            $p['workflowFailureContext'] = $this->workflowFailureContext;
        }

        return $p;
    }
}
