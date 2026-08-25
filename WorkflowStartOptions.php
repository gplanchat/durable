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
         * Récurrence. Le serveur relance une exécution à chaque échéance ; la précédente doit
         * être terminée, sinon l'échéance est sautée.
         */
        public ?CronSchedule $cronSchedule = null,
        public ?TaskQueue $taskQueue = null,
        ?WorkflowTimeouts $timeouts = null,
        public WorkflowIdReusePolicy $workflowIdReusePolicy = WorkflowIdReusePolicy::AllowDuplicateFailedOnly,
    ) {
        $this->timeouts = $timeouts ?? WorkflowTimeouts::none();
    }

    public static function defaults(): self
    {
        return new self();
    }

    public static function cron(CronSchedule|string $schedule): self
    {
        return new self(cronSchedule: CronSchedule::from($schedule));
    }

    /**
     * @return array<string, mixed>
     */
    public function toStartMetadata(): array
    {
        $m = [];
        if (null !== $this->cronSchedule) {
            $m['cron_schedule'] = $this->cronSchedule->toExpression();
        }
        if (null !== $this->taskQueue) {
            $m['task_queue'] = $this->taskQueue->name();
        }
        $m += $this->timeouts->toMetadata();
        $m['workflow_id_reuse_policy'] = $this->workflowIdReusePolicy->value;

        return $m;
    }
}
