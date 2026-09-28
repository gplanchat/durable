<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunFilter;
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
     * A filtered page holds `$limit` runs while enough match, but may come back shorter, even
     * empty, with a `nextCursor` all the same: Temporal on a visibility store that ignores case
     * returns runs the prefix does not match, and the catalog drops them after a few extra trips.
     * Only a `null` cursor means there is nothing after.
     *
     * @param WorkflowRunStatus|null $status `null` for every outcome
     * @param string|null            $cursor       `nextCursor` of a previous page, obtained from
     *                                             the same catalog and with the same filters;
     *                                             `null` for the first page
     * @param WorkflowRunFilter|null $filter       the workflow name and the execution-id prefix,
     *                                             compared as the application wrote them, case
     *                                             included (#558, #557); `null` for every run
     */
    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20, ?WorkflowRunFilter $filter = null): WorkflowRunPage;

    /**
     * Whether {@see listRuns()} honours this filter, or, with none, any filter at all. When it does
     * not, the filter makes it throw {@see \Gplanchat\Durable\Exception\RunFilterUnavailableException}:
     * a surface asks first, and offers only the controls the answer allows. Temporal filters only
     * once Durable writes its search attributes (#558), and by execution-id prefix only from Server
     * 1.23.0, the first that accepts STARTS_WITH (#523).
     */
    public function canFilterRuns(?WorkflowRunFilter $filter = null): bool;

    /**
     * One execution, by the id the application started it with, the `executionId` a description
     * carries (#514); `null` when the catalog has no such execution. On a backend that chains runs
     * under one execution (Temporal's continue-as-new), the current run of the chain.
     *
     * A run page links to a run by this id alone (#264): no cursor, no filter, no page. Paging
     * {@see listRuns()} until the id shows up would make an old run unreachable.
     */
    public function findRun(string $executionId): ?WorkflowRunDescription;

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
