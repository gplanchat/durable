<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Échec d’un workflow enfant observé par le parent (journal {@see \Gplanchat\Durable\Event\ChildWorkflowFailed}).
 *
 * Les champs {@see $workflowFailureKind}, {@see $workflowFailureClass} et {@see $workflowFailureContext}
 * reflètent le dernier {@see \Gplanchat\Durable\Event\WorkflowExecutionFailed} enfant lorsqu’ils ont été
 * projetés sur le journal parent (async Messenger) ou relus au replay.
 */
final class DurableChildWorkflowFailedException extends \RuntimeException
{
    /**
     * @param array<string, mixed> $workflowFailureContext
     */
    public function __construct(
        public readonly string $childExecutionId,
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
        public readonly ?string $workflowFailureKind = null,
        public readonly ?string $workflowFailureClass = null,
        public readonly array $workflowFailureContext = [],
    ) {
        parent::__construct($message, $code, $previous);
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
}
