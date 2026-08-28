<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Projette l’échec d’un enfant async sur {@see ChildWorkflowFailed} en s’appuyant sur le journal enfant
 * ({@see WorkflowExecutionFailed}) lorsqu’il est présent.
 */
final class AsyncChildWorkflowFailureProjector
{
    public static function toParentJournalEvent(
        EventStoreInterface $store,
        string $parentExecutionId,
        string $childExecutionId,
        \Throwable $failure,
    ): ChildWorkflowFailed {
        $wf = self::lastWorkflowExecutionFailed($store, $childExecutionId);
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

    private static function lastWorkflowExecutionFailed(EventStoreInterface $store, string $executionId): ?WorkflowExecutionFailed
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
