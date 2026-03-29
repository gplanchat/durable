<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Options pour {@see ExecutionContext::executeChildWorkflow()} (équivalent {@see \Temporal\Workflow\ChildWorkflowOptions}).
 *
 * Les timeouts sont en secondes. Les champs supplémentaires sont journalisés pour observabilité ;
 * le moteur inline n’applique pas encore tous les timeouts côté exécution.
 */
final readonly class ChildWorkflowOptions
{
    public function __construct(
        /**
         * Identifiant d’exécution enfant (clé du journal enfant). Si null, un UUID est généré.
         */
        public ?string $workflowId = null,
        public ParentClosePolicy $parentClosePolicy = ParentClosePolicy::Terminate,
        public ?string $namespace = null,
        public ?string $taskQueue = null,
        public ?float $workflowExecutionTimeoutSeconds = null,
        public ?float $workflowRunTimeoutSeconds = null,
        public ?float $workflowTaskTimeoutSeconds = null,
        public ?string $cronSchedule = null,
        /** @var array<string, mixed>|null */
        public ?array $memo = null,
        /** @var array<string, mixed>|null */
        public ?array $searchAttributes = null,
        public WorkflowIdReusePolicy $workflowIdReusePolicy = WorkflowIdReusePolicy::AllowDuplicateFailedOnly,
        public ?string $staticSummary = null,
        public ?string $staticDetails = null,
    ) {
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
        if (null !== $this->namespace && '' !== $this->namespace) {
            $m['namespace'] = $this->namespace;
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
        if (null !== $this->cronSchedule && '' !== $this->cronSchedule) {
            $m['cron_schedule'] = $this->cronSchedule;
        }
        if (null !== $this->memo) {
            $m['memo'] = $this->memo;
        }
        if (null !== $this->searchAttributes) {
            $m['search_attributes'] = $this->searchAttributes;
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
