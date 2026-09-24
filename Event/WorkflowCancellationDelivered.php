<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * The requested cancellation was raised inside the workflow, at the await it was waiting on.
 *
 * `targets` are the operations withdrawn at that point. It is empty when the workflow was waiting
 * on a condition: nothing in the journal would then carry the delivery, and a replay would raise
 * it again wherever it first suspends (#317). The Temporal marker of the same name records it too.
 */
final readonly class WorkflowCancellationDelivered implements Event
{
    /**
     * @param list<string> $targets
     */
    public function __construct(
        private string $executionId,
        private array $targets,
    ) {}

    public function executionId(): string
    {
        return $this->executionId;
    }

    /**
     * @return list<string>
     */
    public function targets(): array
    {
        return $this->targets;
    }

    public function payload(): array
    {
        return ['targets' => $this->targets];
    }
}
