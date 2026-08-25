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
    /** Les bornes temporelles de l'exécution, prises ensemble. */
    public WorkflowTimeouts $timeouts;

    public function __construct(
        /**
         * Expression cron (5 champs, ou `@every 1h`). Le serveur relance une exécution à chaque
         * échéance ; la précédente doit être terminée, sinon l'échéance est sautée.
         */
        public ?string $cronSchedule = null,
        public ?string $taskQueue = null,
        ?WorkflowTimeouts $timeouts = null,
        public WorkflowIdReusePolicy $workflowIdReusePolicy = WorkflowIdReusePolicy::AllowDuplicateFailedOnly,
    ) {
        $this->timeouts = $timeouts ?? WorkflowTimeouts::none();
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
        $m += $this->timeouts->toMetadata();
        $m['workflow_id_reuse_policy'] = $this->workflowIdReusePolicy->value;

        return $m;
    }
}
