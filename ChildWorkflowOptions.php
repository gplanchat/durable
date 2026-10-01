<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Exception\UnsupportedByBackendException;

/**
 * Options for {@see ExecutionContext::executeChildWorkflow()} (the equivalent of {@see \Temporal\Workflow\ChildWorkflowOptions}).
 *
 * The extra fields are logged for observability; the inline engine does not yet enforce all
 * the timeouts on the execution side.
 */
final readonly class ChildWorkflowOptions
{
    /** The memo key a child's journal reads its execution id from; Durable writes it (#889). */
    public const MEMO_KEY_DURABLE_EXECUTION_ID = 'durableExecutionId';

    /** The memo key that says what a suspended run waits on; Durable overwrites it (#514, #889). */
    public const MEMO_KEY_DURABLE_WAITING_ON = 'durableWaitingOn';

    /** The time bounds of the child, taken together. */
    public WorkflowTimeouts $timeouts;

    /** What the child will be findable by. */
    public SearchAttributes $searchAttributes;

    public function __construct(
        /**
         * Child execution identifier (the key of the child log). If null, a UUID is generated.
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
        foreach ([self::MEMO_KEY_DURABLE_EXECUTION_ID, self::MEMO_KEY_DURABLE_WAITING_ON] as $reserved) {
            if (null !== $memo && \array_key_exists($reserved, $memo)) {
                throw new UnsupportedByBackendException(\sprintf('The key "%s" in ChildWorkflowOptions::$memo is reserved: Durable writes this key itself; choose another key.', $reserved));
            }
        }
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
