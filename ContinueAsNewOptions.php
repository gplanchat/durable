<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Options for {@see ExecutionContext::continueAsNew()} (the equivalent of {@see \Temporal\Workflow\ContinueAsNewOptions}).
 *
 * A continue-as-new opens a new run **within** the current execution: the execution bound is
 * inherited and is not set again here.
 */
final readonly class ContinueAsNewOptions
{
    /** Bounds of the next run; the execution bound is refused here. */
    public WorkflowTimeouts $timeouts;

    public function __construct(
        public ?TaskQueue $taskQueue = null,
        ?WorkflowTimeouts $timeouts = null,
    ) {
        $timeouts ??= WorkflowTimeouts::none();
        if (null !== $timeouts->execution) {
            throw new \InvalidArgumentException(
                'Continue-as-new cannot set an execution timeout: the new run belongs to the '
                . 'current execution and inherits it. Use WorkflowTimeouts::withoutExecutionBound().',
            );
        }

        $this->timeouts = $timeouts;
    }

    public static function new(): self
    {
        return new self();
    }

    public function withTaskQueue(TaskQueue|string|null $taskQueue): self
    {
        return new self(TaskQueue::fromNullable($taskQueue), $this->timeouts);
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
        if (null !== $this->taskQueue) {
            $m['task_queue'] = $this->taskQueue->name();
        }

        return $m + $this->timeouts->toMetadata();
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromMetadata(array $metadata): self
    {
        return new self(
            TaskQueue::fromNullable(isset($metadata['task_queue']) ? (string) $metadata['task_queue'] : null),
            WorkflowTimeouts::fromMetadata($metadata)->withoutExecutionBound(),
        );
    }
}
