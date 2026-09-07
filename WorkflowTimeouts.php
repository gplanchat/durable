<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * The time bounds of a workflow, taken together.
 *
 * As with activities, each one bounds a different segment — and it is the way they nest that
 * carries the meaning:
 *
 *     execution ─┬─ run 1 ─┬─ run 2 (continue-as-new, retry) ─ …
 *                │         └─ task: one worker round trip
 *                └────────────── execution: the whole chain
 *
 * The three fields were three `?float` repeated identically in {@see ChildWorkflowOptions},
 * {@see WorkflowStartOptions} and {@see ContinueAsNewOptions}, with the same serialisation
 * copied three times.
 */
final readonly class WorkflowTimeouts
{
    public function __construct(
        /** The whole chain of executions, retries and continue-as-new included. */
        public ?Duration $execution = null,
        /** A single run, taken on its own. */
        public ?Duration $run = null,
        /** A workflow task: one decision round trip on the worker side. */
        public ?Duration $task = null,
    ) {
        if (null !== $run && null !== $execution && $run->isLongerThan($execution)) {
            throw new \InvalidArgumentException(\sprintf(
                'Run timeout (%s) cannot exceed execution timeout (%s): the execution would end first. '
                . 'Temporal silently rewrites the run timeout down to the execution timeout.',
                $run,
                $execution,
            ));
        }
    }

    public static function none(): self
    {
        return new self();
    }

    /**
     * Bound a run.
     */
    public static function run(Duration $run): self
    {
        return new self(run: $run);
    }

    public function withExecution(?Duration $duration): self
    {
        return new self($duration, $this->run, $this->task);
    }

    public function withRun(?Duration $duration): self
    {
        return new self($this->execution, $duration, $this->task);
    }

    public function withTask(?Duration $duration): self
    {
        return new self($this->execution, $this->run, $duration);
    }

    public function areUnbounded(): bool
    {
        return null === $this->execution && null === $this->run && null === $this->task;
    }

    /**
     * Without the execution bound.
     *
     * A continue-as-new opens a new run **within** the current execution: the execution bound
     * is inherited, and setting it again would make no sense
     * ({@see \Temporal\Api\Command\V1\ContinueAsNewWorkflowExecutionCommandAttributes} has no
     * such field).
     */
    public function withoutExecutionBound(): self
    {
        return new self(null, $this->run, $this->task);
    }

    /**
     * @return array<string, float>
     */
    public function toMetadata(): array
    {
        $m = [];
        foreach ([
            'workflow_execution_timeout_seconds' => $this->execution,
            'workflow_run_timeout_seconds' => $this->run,
            'workflow_task_timeout_seconds' => $this->task,
        ] as $key => $duration) {
            if (null !== $duration) {
                $m[$key] = $duration->toSeconds();
            }
        }

        return $m;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromMetadata(array $metadata): self
    {
        return new self(
            Duration::fromWireValue($metadata['workflow_execution_timeout_seconds'] ?? null),
            Duration::fromWireValue($metadata['workflow_run_timeout_seconds'] ?? null),
            Duration::fromWireValue($metadata['workflow_task_timeout_seconds'] ?? null),
        );
    }
}
