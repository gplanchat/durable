<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Les bornes temporelles d'un workflow, prises ensemble.
 *
 * Comme pour les activités, chacune borne un segment différent — et c'est leur emboîtement qui
 * a un sens :
 *
 *     exécution ─┬─ run 1 ─┬─ run 2 (continue-as-new, retry) ─ …
 *                │         └─ tâche : un aller-retour worker
 *                └────────────── execution : toute la chaîne
 *
 * Les trois champs étaient trois `?float` répétés à l'identique dans {@see ChildWorkflowOptions},
 * {@see WorkflowStartOptions} et {@see ContinueAsNewOptions}, avec la même sérialisation copiée
 * trois fois.
 */
final readonly class WorkflowTimeouts
{
    public function __construct(
        /** Toute la chaîne d'exécutions, retentatives et continue-as-new compris. */
        public ?Duration $execution = null,
        /** Un run pris isolément. */
        public ?Duration $run = null,
        /** Une tâche de workflow : un aller-retour de décision côté worker. */
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
     * Borner un run.
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
     * Sans la borne d'exécution.
     *
     * Un continue-as-new ouvre un nouveau run **dans** l'exécution en cours : la borne
     * d'exécution est héritée, la reposer n'aurait aucun sens
     * ({@see \Temporal\Api\Command\V1\ContinueAsNewWorkflowExecutionCommandAttributes} n'a pas
     * ce champ).
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
