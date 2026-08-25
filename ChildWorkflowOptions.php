<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Options pour {@see ExecutionContext::executeChildWorkflow()} (équivalent {@see \Temporal\Workflow\ChildWorkflowOptions}).
 *
 * Les champs supplémentaires sont journalisés pour observabilité ; le moteur inline n’applique
 * pas encore tous les timeouts côté exécution.
 */
final readonly class ChildWorkflowOptions
{
    /** Les bornes temporelles de l'enfant, prises ensemble. */
    public WorkflowTimeouts $timeouts;

    /** Ce sur quoi l'enfant pourra être retrouvé. */
    public SearchAttributes $searchAttributes;

    public function __construct(
        /**
         * Identifiant d’exécution enfant (clé du journal enfant). Si null, un UUID est généré.
         */
        public ?string $workflowId = null,
        public ParentClosePolicy $parentClosePolicy = ParentClosePolicy::Terminate,
        public ?WorkflowNamespace $namespace = null,
        public ?TaskQueue $taskQueue = null,
        ?WorkflowTimeouts $timeouts = null,
        public ?CronSchedule $cronSchedule = null,
        /** @var array<string, mixed>|null */
        public ?array $memo = null,
        ?SearchAttributes $searchAttributes = null,
        public WorkflowIdReusePolicy $workflowIdReusePolicy = WorkflowIdReusePolicy::AllowDuplicateFailedOnly,
        public ?string $staticSummary = null,
        public ?string $staticDetails = null,
    ) {
        $this->timeouts = $timeouts ?? WorkflowTimeouts::none();
        $this->searchAttributes = $searchAttributes ?? SearchAttributes::none();
    }

    public static function defaults(): self
    {
        return new self();
    }

    /**
     * @return array<string, mixed>
     */
    public function toSchedulingMetadata(): array
    {
        $m = [];
        if (null !== $this->namespace) {
            $m['namespace'] = $this->namespace->name();
        }
        if (null !== $this->taskQueue) {
            $m['task_queue'] = $this->taskQueue->name();
        }
        $m += $this->timeouts->toMetadata();
        if (null !== $this->cronSchedule) {
            $m['cron_schedule'] = $this->cronSchedule->toExpression();
        }
        if (null !== $this->memo) {
            $m['memo'] = $this->memo;
        }
        if (!$this->searchAttributes->isEmpty()) {
            $m['search_attributes'] = $this->searchAttributes->toMetadata();
        }
        $m['workflow_id_reuse_policy'] = $this->workflowIdReusePolicy->value;
        if (null !== $this->staticSummary && '' !== $this->staticSummary) {
            $m['static_summary'] = $this->staticSummary;
        }
        if (null !== $this->staticDetails && '' !== $this->staticDetails) {
            $m['static_details'] = $this->staticDetails;
        }

        return $m;
    }
}
