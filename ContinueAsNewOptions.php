<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Options pour {@see ExecutionContext::continueAsNew()} (équivalent {@see \Temporal\Workflow\ContinueAsNewOptions}).
 *
 * Un continue-as-new ouvre un nouveau run **dans** l'exécution en cours : la borne d'exécution
 * est héritée et ne se repose pas ici.
 */
final readonly class ContinueAsNewOptions
{
    /** Bornes du prochain run ; la borne d'exécution y est refusée. */
    public WorkflowTimeouts $timeouts;

    public function __construct(
        public ?string $taskQueue = null,
        ?WorkflowTimeouts $timeouts = null,
    ) {
        $timeouts ??= WorkflowTimeouts::none();
        if (null !== $timeouts->execution) {
            throw new \InvalidArgumentException(
                'Continue-as-new cannot set an execution timeout: the new run belongs to the '
                .'current execution and inherits it. Use WorkflowTimeouts::withoutExecutionBound().',
            );
        }

        $this->timeouts = $timeouts;
    }

    public static function new(): self
    {
        return new self();
    }

    public function withTaskQueue(?string $taskQueue): self
    {
        return new self($taskQueue, $this->timeouts);
    }

    public function withTimeouts(WorkflowTimeouts $timeouts): self
    {
        return new self($this->taskQueue, $timeouts);
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

        return $m + $this->timeouts->toMetadata();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromMetadata(array $metadata): self
    {
        return new self(
            isset($metadata['task_queue']) ? (string) $metadata['task_queue'] : null,
            WorkflowTimeouts::fromMetadata($metadata)->withoutExecutionBound(),
        );
    }
}
