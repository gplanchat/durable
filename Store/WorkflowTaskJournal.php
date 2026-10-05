<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\WorkflowTaskCompleted;
use Gplanchat\Durable\Event\WorkflowTaskScheduled;
use Gplanchat\Durable\Event\WorkflowTaskStarted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Port\NullWorkflowResumeDispatcher;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;

/**
 * Journals that a pass over a workflow is queued, from the places that dispatch a resume.
 *
 * Two dispatches can follow each other before a worker takes the first (an activity outcome and a
 * timer, say): the second adds no wait of its own, so it is not journalled. The check reads the
 * stream.
 */
final class WorkflowTaskJournal
{
    public static function schedule(EventStoreInterface $store, WorkflowResumeDispatcher $dispatcher, ExecutionId $executionId): void
    {
        // Inline mode and the Temporal activity worker dispatch nothing: no task is queued.
        if ($dispatcher instanceof NullWorkflowResumeDispatcher) {
            return;
        }

        $lastWasScheduled = false;
        // ponytail: a full read per dispatch; an indexed "last task event" if streams get long.
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof WorkflowTaskScheduled) {
                $lastWasScheduled = true;
            } elseif ($event instanceof WorkflowTaskStarted || $event instanceof WorkflowTaskCompleted) {
                $lastWasScheduled = false;
            }
        }

        if (!$lastWasScheduled) {
            $store->append(new WorkflowTaskScheduled($executionId));
        }
    }
}
