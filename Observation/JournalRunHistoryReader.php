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
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\NexusOperationCancelled;
use Gplanchat\Durable\Event\NexusOperationCompleted;
use Gplanchat\Durable\Event\NexusOperationFailed;
use Gplanchat\Durable\Event\NexusOperationScheduled;
use Gplanchat\Durable\Event\NexusOperationTimedOut;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
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
    /**
     * La clé de l'action que l'exécution est pour elle-même. Une seule par historique, et la
     * première : c'est son démarrage qui ouvre le flux.
     */
    private const RUN_ACTION = 'workflow';

    public function __construct(
        private readonly EventStoreInterface $events,
    ) {}

    /**
     * @return list<WorkflowRunEvent>
     */
    public function read(string $runId, string $workflowName = ''): array
    {
        /** @var array<string, string> $activityNames */
        $activityNames = [];
        // Les événements terminaux d'une opération Nexus ne portent que le `scheduledEventId` :
        // l'identité — endpoint, service, opération — n'est écrite que sur la planification. Même
        // contrainte que pour les activités, même remède.
        /** @var array<int, string> $nexusNames */
        $nexusNames = [];
        // Même contrainte que pour les activités : seule la planification connaît le résumé.
        /** @var array<string, string> $timerNames */
        $timerNames = [];
        /** @var array<string, string> $childNames */
        $childNames = [];
        $history = [];
        $sequence = 0;

        foreach ($this->events->readStreamWithRecordedAt($runId) as $entry) {
            $event = $entry['event'];
            $recordedAt = $entry['recordedAt'] ?? null;

            if ($event instanceof ActivityScheduled) {
                $activityNames[$event->activityId()] = $event->activityName();
            }

            if ($event instanceof ChildWorkflowScheduled) {
                $childNames[$event->childExecutionId()] = $event->childWorkflowType();
            }

            if ($event instanceof TimerScheduled && '' !== $event->summary()) {
                $timerNames[$event->timerId()] = $event->summary();
            }

            if ($event instanceof NexusOperationScheduled) {
                $nexusNames[$event->scheduledEventId()] = self::nexusLabel(
                    $event->endpoint(),
                    $event->service(),
                    $event->operation(),
                );
            }

            $history[] = new WorkflowRunEvent(
                ++$sequence,
                $recordedAt instanceof \DateTimeImmutable ? $recordedAt : new \DateTimeImmutable('@0'),
                self::kindOf($event),
                self::labelOf($event, $activityNames, $nexusNames, $timerNames, $childNames, $workflowName),
                // `payload()` est sur l'interface `Event` : c'est la forme sérialisée que
                // l'événement se donne déjà pour être écrit puis relu. Rien à traduire, et rien à
                // choisir non plus — filtrer ici reviendrait à décider à la place de l'exploitant
                // ce qui mérite d'être vu le jour où quelque chose ne va pas.
                $event->payload(),
                self::actionKeyOf($event),
            );
        }

        return $history;
    }

    /**
     * L'action dont l'événement fait partie, ou `null` quand il est à lui seul la sienne.
     *
     * Le journal corrèle déjà : une activité par son `activityId`, un minuteur par son `timerId`,
     * une opération Nexus par l'identifiant de sa planification. Il n'y a rien à inventer ici, juste
     * à cesser de jeter le lien au moment de traduire.
     */
    private static function actionKeyOf(Event $event): ?string
    {
        // L'exécution elle-même est une action : son démarrage, sa fin, son annulation. Un signal
        // reçu ou une mise à jour n'en font pas partie — ce sont des actions à part entière, et
        // c'est pour cela que la liste est écrite plutôt que dérivée de la voie `Execution`.
        if ($event instanceof ExecutionStarted
            || $event instanceof ExecutionCompleted
            || $event instanceof WorkflowExecutionFailed
            || $event instanceof WorkflowExecutionCancelled
            || $event instanceof WorkflowCancellationRequested
            || $event instanceof WorkflowContinuedAsNew
        ) {
            return self::RUN_ACTION;
        }

        if ($event instanceof ChildWorkflowScheduled
            || $event instanceof ChildWorkflowCompleted
            || $event instanceof ChildWorkflowFailed
        ) {
            return 'child:' . $event->childExecutionId();
        }

        $activityId = self::activityIdOf($event);
        if (null !== $activityId) {
            return 'activity:' . $activityId;
        }

        if ($event instanceof TimerScheduled
            || $event instanceof TimerCompleted
            || $event instanceof TimerCancelled
        ) {
            return 'timer:' . $event->timerId();
        }

        if ($event instanceof NexusOperationScheduled) {
            return 'nexus:' . $event->scheduledEventId();
        }

        $scheduledEventId = self::nexusScheduledEventIdOf($event);

        return null === $scheduledEventId ? null : 'nexus:' . $scheduledEventId;
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

            $event instanceof NexusOperationScheduled,
            $event instanceof NexusOperationCompleted,
            $event instanceof NexusOperationFailed,
            $event instanceof NexusOperationTimedOut,
            $event instanceof NexusOperationCancelled => WorkflowRunEventKind::Nexus,

            $event instanceof WorkflowSignalReceived => WorkflowRunEventKind::Signal,
            $event instanceof WorkflowUpdateHandled => WorkflowRunEventKind::Update,

            default => WorkflowRunEventKind::Other,
        };
    }

    /**
     * @param array<string, string> $activityNames
     * @param array<int, string>    $nexusNames
     * @param array<string, string> $timerNames
     * @param array<string, string> $childNames
     */
    private static function labelOf(
        Event $event,
        array $activityNames,
        array $nexusNames,
        array $timerNames,
        array $childNames,
        string $workflowName,
    ): string {
        // Une ligne de frise porte le nom de son action, et l'action de l'exécution est
        // l'exécution : « ExecutionStarted » nomme une classe d'événement, pas ce qui tourne.
        // Le journal ne connaît pas ce nom — il n'a qu'un flux — donc l'appelant le lui donne.
        if ($event instanceof ExecutionStarted && '' !== $workflowName) {
            return $workflowName;
        }

        if ($event instanceof ChildWorkflowScheduled) {
            return $event->childWorkflowType();
        }

        if ($event instanceof ChildWorkflowCompleted || $event instanceof ChildWorkflowFailed) {
            return $childNames[$event->childExecutionId()] ?? ('child ' . $event->childExecutionId());
        }

        if ($event instanceof NexusOperationScheduled) {
            return self::nexusLabel($event->endpoint(), $event->service(), $event->operation());
        }

        $scheduledEventId = self::nexusScheduledEventIdOf($event);
        if (null !== $scheduledEventId) {
            // Sans la planification — un journal tronqué, une lecture partielle — mieux vaut
            // nommer l'identifiant que rendre une étiquette qui ne désigne rien.
            return $nexusNames[$scheduledEventId] ?? ('nexus #' . $scheduledEventId);
        }

        if ($event instanceof TimerScheduled
            || $event instanceof TimerCompleted
            || $event instanceof TimerCancelled
        ) {
            // Une voie de frise porte le nom de son action. « TimerScheduled » nomme la classe,
            // pas l'attente : `timer 5s avant relance` dit ce qu'un exploitant est venu lire.
            return $timerNames[$event->timerId()] ?? ('timer ' . $event->timerId());
        }

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

    private static function nexusLabel(string $endpoint, string $service, string $operation): string
    {
        return \sprintf('%s/%s/%s', $endpoint, $service, $operation);
    }

    private static function nexusScheduledEventIdOf(Event $event): ?int
    {
        return match (true) {
            $event instanceof NexusOperationCompleted,
            $event instanceof NexusOperationFailed,
            $event instanceof NexusOperationTimedOut,
            $event instanceof NexusOperationCancelled => $event->scheduledEventId(),
            default => null,
        };
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
