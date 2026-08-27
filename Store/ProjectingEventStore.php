<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunStatus;

/**
 * Décore le journal pour y lire l'issue des exécutions.
 *
 * Les quatre fins arrivent ici typées et en un seul endroit — `EventStoreWorkflowLifecycle` les
 * ajoute toutes —, là où le magasin de métadonnées les confond dans un même `delete()`. C'est ce
 * qui rend un décorateur possible ici et impossible là-bas.
 *
 * Ce cycle de vie est celui du backend journal ; Temporal utilise `TemporalWorkflowLifecycle`,
 * donc rien de tout ceci ne se déclenche sur une application Temporal.
 *
 * @see openspec/changes/backend-neutral-workflow-dashboard/design.md
 */
final class ProjectingEventStore implements EventStoreInterface
{
    public function __construct(
        private readonly EventStoreInterface $inner,
        private readonly WorkflowRunProjectionInterface $projection,
    ) {}

    public function append(Event $event): void
    {
        $this->inner->append($event);

        $status = self::outcomeOf($event);
        if (null !== $status) {
            $this->projection->recordOutcome($event->executionId(), $status);
        }
    }

    public function readStream(string $executionId): iterable
    {
        return $this->inner->readStream($executionId);
    }

    public function readStreamWithRecordedAt(string $executionId): iterable
    {
        return $this->inner->readStreamWithRecordedAt($executionId);
    }

    public function countEventsInStream(string $executionId): int
    {
        return $this->inner->countEventsInStream($executionId);
    }

    /**
     * `null` pour tout ce qui n'est pas une fin : la projection ne bouge qu'aux quatre transitions
     * qui terminent une exécution.
     */
    private static function outcomeOf(Event $event): ?WorkflowRunStatus
    {
        return match (true) {
            $event instanceof ExecutionCompleted => WorkflowRunStatus::Completed,
            $event instanceof WorkflowExecutionFailed => WorkflowRunStatus::Failed,
            $event instanceof WorkflowExecutionCancelled => WorkflowRunStatus::Cancelled,
            $event instanceof WorkflowContinuedAsNew => WorkflowRunStatus::ContinuedAsNew,
            default => null,
        };
    }
}
