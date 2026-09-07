<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Start options for a root execution (the counterpart of {@see ChildWorkflowOptions} for
 * children).
 *
 * The metadata keys are deliberately identical to those of
 * {@see ChildWorkflowOptions::toSchedulingMetadata()}: root and child describe the same
 * settings, and the Temporal bridge reads them in the same place.
 */
final readonly class WorkflowStartOptions
{
    /** The time bounds of the execution, taken together. */
    public WorkflowTimeouts $timeouts;

    /** What the execution will be findable by. */
    public SearchAttributes $searchAttributes;

    public function __construct(
        /**
         * Recurrence. The server starts an execution at every due time; the previous one must
         * be finished, otherwise the due time is skipped.
         */
        public ?CronSchedule $cronSchedule = null,
        public ?TaskQueue $taskQueue = null,
        ?WorkflowTimeouts $timeouts = null,
        public WorkflowIdReusePolicy $workflowIdReusePolicy = WorkflowIdReusePolicy::AllowDuplicateFailedOnly,
        ?SearchAttributes $searchAttributes = null,
    ) {
        $this->timeouts = $timeouts ?? WorkflowTimeouts::none();
        $this->searchAttributes = $searchAttributes ?? SearchAttributes::none();
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
        if (!$this->searchAttributes->isEmpty()) {
            $m['search_attributes'] = $this->searchAttributes->toMetadata();
        }

        return $m;
    }
}
