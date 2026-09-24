<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Failure of a child workflow observed by the parent (journal {@see \Gplanchat\Durable\Event\ChildWorkflowFailed}).
 *
 * The {@see $workflowFailureKind}, {@see $workflowFailureClass} and {@see $workflowFailureContext}
 * fields reflect the last child {@see \Gplanchat\Durable\Event\WorkflowExecutionFailed}, projected
 * onto the parent journal for an inline child and an async one alike, and read back from it on the
 * first pass as on replay. A child that ended otherwise (cancelled) leaves them empty. On
 * Temporal they are not filled yet.
 */
final class DurableChildWorkflowFailedException extends \RuntimeException implements ExceptionInterface
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
