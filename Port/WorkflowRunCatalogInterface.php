<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunStatus;

/**
 * Read-only: which executions exist, and what became of them.
 *
 * The component had no listing surface at all — {@see \Gplanchat\Durable\Store\EventStoreInterface}
 * only reads one stream per execution id, {@see \Gplanchat\Durable\Store\WorkflowMetadataStore} one
 * execution at a time. A dashboard reads across executions; that is another need, and this port is
 * here so that it is not served by speaking gRPC or SQL from the view.
 *
 * Implementations return {@see \Gplanchat\Durable\Observation\WorkflowRunDescription}: what the
 * backend can say, and nothing it could not.
 */
interface WorkflowRunCatalogInterface
{
    /**
     * A page of executions, from the most recently started to the oldest.
     *
     * @param WorkflowRunStatus|null $status `null` for every outcome
     * @param string|null            $cursor `nextCursor` of a previous page, obtained from the
     *                                       same catalog and with the same filter; `null` for the
     *                                       first page
     */
    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20): WorkflowRunPage;

    /**
     * The recorded history of an execution, in the order it was recorded.
     *
     * Takes the **description** and not the identifier alone: Temporal demands the workflow id on
     * top of the run id to retrieve a history, and it lives in `groupId`. A port that passed only
     * the identifier would force the caller to retrieve it by its own means, that is, to know
     * which backend it is talking about.
     *
     * An unknown execution returns an empty list: an execution that was purged, or never seen, is
     * not a call error — the view must be able to display it with nothing to catch up on.
     *
     * @return list<WorkflowRunEvent>
     */
    public function readHistory(WorkflowRunDescription $run): array;

    /**
     * Whether the backend answers, right now.
     *
     * Never throws: a probe that fails is a diagnosis, not a failure of the caller. The page must
     * be able to display "unreachable" rather than return a five-hundred error.
     */
    public function checkHealth(): BackendHealth;
}
