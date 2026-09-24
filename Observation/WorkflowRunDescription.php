<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * What a backend can say about an execution, in the component's vocabulary and not in its own.
 *
 * The facts a backend does not have are **absent**, never an empty string: an empty "task queue"
 * column teaches the operator that the execution has no queue, when in fact it is the backend that
 * has no such notion. Hence nullable properties rather than filler values.
 *
 * `groupId` carries the grouping when the backend has one — Temporal keeps the workflow id across
 * continuations and gives each execution its own run id. The DBAL backend has no such notion and
 * leaves it absent.
 *
 * `waitingForWorkerSince` is set while a running execution has not been picked up by any worker: it
 * was dispatched, and nothing consumed it (#447). It is absent once a worker picked it up, on an
 * ended run, and on a backend that cannot tell.
 */
final readonly class WorkflowRunDescription
{
    public function __construct(
        public string $runId,
        public string $workflowName,
        public WorkflowRunStatus $status,
        public ?\DateTimeImmutable $startedAt = null,
        public ?\DateTimeImmutable $endedAt = null,
        public ?string $groupId = null,
        public ?\DateTimeImmutable $waitingForWorkerSince = null,
    ) {}
}
