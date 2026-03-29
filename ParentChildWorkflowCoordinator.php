<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Port\ParentChildWorkflowCoordinatorInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Applique {@see ParentClosePolicy} sur les enfants encore actifs lorsque le parent se ferme.
 */
final class ParentChildWorkflowCoordinator implements ParentChildWorkflowCoordinatorInterface
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ?WorkflowResumeDispatcher $resumeDispatcher = null,
    ) {
    }

    public function onParentClosed(string $parentExecutionId, ParentClosureReason $reason): void
    {
        foreach ($this->collectScheduledChildren($parentExecutionId) as $row) {
            if (!self::isChildRunActive($this->eventStore, $row['childExecutionId'])) {
                continue;
            }

            match ($row['policy']) {
                ParentClosePolicy::Terminate => $this->terminateChild($row['childExecutionId'], $parentExecutionId),
                ParentClosePolicy::Abandon => null,
                ParentClosePolicy::RequestCancel => $this->requestCancelChild($row['childExecutionId'], $parentExecutionId),
            };
        }
    }

    public static function isChildRunActive(EventStoreInterface $store, string $childExecutionId): bool
    {
        $started = false;
        foreach ($store->readStream($childExecutionId) as $event) {
            if ($event instanceof ExecutionStarted) {
                $started = true;
            }
            if ($event instanceof ExecutionCompleted || $event instanceof WorkflowExecutionFailed) {
                return false;
            }
        }

        return $started;
    }

    /**
     * @return list<array{childExecutionId: string, policy: ParentClosePolicy}>
     */
    private function collectScheduledChildren(string $parentExecutionId): array
    {
        $out = [];
        foreach ($this->eventStore->readStream($parentExecutionId) as $event) {
            if ($event instanceof ChildWorkflowScheduled) {
                $out[] = [
                    'childExecutionId' => $event->childExecutionId(),
                    'policy' => $event->parentClosePolicy(),
                ];
            }
        }

        return $out;
    }

    private function terminateChild(string $childExecutionId, string $parentExecutionId): void
    {
        $this->eventStore->append(WorkflowExecutionFailed::terminatedByParent(
            $childExecutionId,
            $parentExecutionId,
        ));
        $this->resumeDispatcher?->dispatchResume($childExecutionId);
    }

    private function requestCancelChild(string $childExecutionId, string $parentExecutionId): void
    {
        $this->eventStore->append(new WorkflowCancellationRequested(
            $childExecutionId,
            'parent_request_cancel',
            $parentExecutionId,
        ));
        $this->resumeDispatcher?->dispatchResume($childExecutionId);
    }
}
