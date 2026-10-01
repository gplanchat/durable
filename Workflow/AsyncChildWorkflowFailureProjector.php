<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Projects an async child's failure onto {@see ChildWorkflowFailed}, relying on the child journal
 * ({@see WorkflowExecutionFailed}) when it is present.
 *
 * @internal
 */
final class AsyncChildWorkflowFailureProjector
{
    private function __construct() {}

    public static function toParentJournalEvent(
        EventStoreInterface $store,
        ExecutionId $parentExecutionId,
        ExecutionId $childExecutionId,
        \Throwable $failure,
        // The run that failed, when it is not the one the parent scheduled: the last of a chain (#859).
        ?ExecutionId $failedRun = null,
    ): ChildWorkflowFailed {
        $wf = self::lastWorkflowExecutionFailed($store, $failedRun ?? $childExecutionId);
        if (null !== $wf) {
            return new ChildWorkflowFailed(
                $parentExecutionId,
                $childExecutionId,
                $wf->failureMessage(),
                $wf->failureCode(),
                $wf->kind(),
                $wf->failureClass(),
                $wf->context(),
            );
        }

        return new ChildWorkflowFailed(
            $parentExecutionId,
            $childExecutionId,
            $failure->getMessage(),
            (int) $failure->getCode(),
        );
    }

    private static function lastWorkflowExecutionFailed(EventStoreInterface $store, ExecutionId $executionId): ?WorkflowExecutionFailed
    {
        $last = null;
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof WorkflowExecutionFailed) {
                $last = $event;
            }
        }

        return $last;
    }
}
