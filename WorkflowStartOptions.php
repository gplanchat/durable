<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Options de démarrage d'une exécution racine (pendant de {@see ChildWorkflowOptions} pour les
 * enfants).
 *
 * Les clés de métadonnées sont volontairement identiques à celles de
 * {@see ChildWorkflowOptions::toSchedulingMetadata()} : racine et enfant décrivent les mêmes
 * réglages, et le pont Temporal les lit au même endroit.
 */
final readonly class WorkflowStartOptions
{
    public function __construct(
        /**
         * Expression cron (5 champs, ou `@every 1h`). Le serveur relance une exécution à chaque
         * échéance ; la précédente doit être terminée, sinon l'échéance est sautée.
         */
        public ?string $cronSchedule = null,
        public ?string $taskQueue = null,
        public ?float $workflowExecutionTimeoutSeconds = null,
        public ?float $workflowRunTimeoutSeconds = null,
        public ?float $workflowTaskTimeoutSeconds = null,
        public WorkflowIdReusePolicy $workflowIdReusePolicy = WorkflowIdReusePolicy::AllowDuplicateFailedOnly,
    ) {
    }

    public static function defaults(): self
    {
        return new self();
    }

    public static function cron(string $expression): self
    {
        return new self(cronSchedule: $expression);
    }

    /**
     * @return array<string, mixed>
     */
    public function toStartMetadata(): array
    {
        $m = [];
        if (null !== $this->cronSchedule && '' !== $this->cronSchedule) {
            $m['cron_schedule'] = $this->cronSchedule;
        }
        if (null !== $this->taskQueue && '' !== $this->taskQueue) {
            $m['task_queue'] = $this->taskQueue;
        }
        if (null !== $this->workflowExecutionTimeoutSeconds) {
            $m['workflow_execution_timeout_seconds'] = $this->workflowExecutionTimeoutSeconds;
        }
        if (null !== $this->workflowRunTimeoutSeconds) {
            $m['workflow_run_timeout_seconds'] = $this->workflowRunTimeoutSeconds;
        }
        if (null !== $this->workflowTaskTimeoutSeconds) {
            $m['workflow_task_timeout_seconds'] = $this->workflowTaskTimeoutSeconds;
        }
        $m['workflow_id_reuse_policy'] = $this->workflowIdReusePolicy->value;

        return $m;
    }
}
