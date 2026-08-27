<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskCompleted;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Traduit un flux de journal en historique lisible.
 *
 * Une seule passe avant suffit pour nommer les activités : la planification précède toujours la
 * complétion dans l'ordre d'enregistrement, donc le nom est connu quand on en a besoin. Une
 * complétion sans sa planification — journal purgé, reprise partielle — retombe sur l'identifiant :
 * un id vaut mieux qu'une ligne sans nom.
 */
final class JournalRunHistoryReader
{
    public function __construct(
        private readonly EventStoreInterface $events,
    ) {}

    /**
     * @return list<WorkflowRunEvent>
     */
    public function read(string $runId): array
    {
        /** @var array<string, string> $activityNames */
        $activityNames = [];
        $history = [];
        $sequence = 0;

        foreach ($this->events->readStreamWithRecordedAt($runId) as $entry) {
            $event = $entry['event'];
            $recordedAt = $entry['recordedAt'] ?? null;

            if ($event instanceof ActivityScheduled) {
                $activityNames[$event->activityId()] = $event->activityName();
            }

            $history[] = new WorkflowRunEvent(
                ++$sequence,
                $recordedAt instanceof \DateTimeImmutable ? $recordedAt : new \DateTimeImmutable('@0'),
                self::kindOf($event),
                self::labelOf($event, $activityNames),
            );
        }

        return $history;
    }

    private static function kindOf(Event $event): WorkflowRunEventKind
    {
        return match (true) {
            $event instanceof ExecutionStarted,
            $event instanceof ExecutionCompleted,
            $event instanceof WorkflowExecutionFailed,
            $event instanceof WorkflowExecutionCancelled,
            $event instanceof WorkflowCancellationRequested,
            $event instanceof WorkflowContinuedAsNew => WorkflowRunEventKind::Execution,

            $event instanceof ActivityScheduled,
            $event instanceof ActivityCompleted,
            $event instanceof ActivityFailed,
            $event instanceof ActivityCancelled,
            $event instanceof ActivityCatastrophicFailure,
            $event instanceof ActivityTaskStarted,
            $event instanceof ActivityTaskCompleted,
            $event instanceof ActivityTaskFailed => WorkflowRunEventKind::Activity,

            $event instanceof WorkflowSignalReceived => WorkflowRunEventKind::Signal,
            $event instanceof WorkflowUpdateHandled => WorkflowRunEventKind::Update,

            default => WorkflowRunEventKind::Other,
        };
    }

    /**
     * @param array<string, string> $activityNames
     */
    private static function labelOf(Event $event, array $activityNames): string
    {
        if ($event instanceof WorkflowSignalReceived) {
            return $event->signalName();
        }
        if ($event instanceof WorkflowUpdateHandled) {
            return $event->updateName();
        }

        $activityId = self::activityIdOf($event);
        if (null !== $activityId) {
            return $activityNames[$activityId] ?? $activityId;
        }

        return self::shortName($event);
    }

    private static function activityIdOf(Event $event): ?string
    {
        return match (true) {
            $event instanceof ActivityScheduled,
            $event instanceof ActivityCompleted,
            $event instanceof ActivityFailed,
            $event instanceof ActivityCancelled,
            $event instanceof ActivityCatastrophicFailure,
            $event instanceof ActivityTaskStarted,
            $event instanceof ActivityTaskCompleted,
            $event instanceof ActivityTaskFailed => $event->activityId(),
            default => null,
        };
    }

    /**
     * Repli lisible pour tout ce qui n'a pas de nom métier : `SideEffectRecorded` vaut mieux que
     * `Gplanchat\Durable\Event\SideEffectRecorded`, et bien mieux que rien.
     */
    private static function shortName(Event $event): string
    {
        $parts = explode('\\', $event::class);

        return end($parts) ?: $event::class;
    }
}
