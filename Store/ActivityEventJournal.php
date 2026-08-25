<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Failure\ActivityRetryState;

/**
 * Détecte si le journal contient déjà une issue terminale pour une activité donnée
 * (succès, échec définitif, annulation). Utilisé pour ignorer les redélivrances Messenger
 * ou les doublons de traitement sans dupliquer les événements.
 *
 * {@see \Gplanchat\Durable\Event\ActivityTaskFailed} et un {@see ActivityFailed} en
 * {@see ActivityRetryState::InProgress} ne sont **pas** terminaux : une retentative reste attendue.
 */
final class ActivityEventJournal
{
    public static function hasTerminalOutcomeForActivity(
        EventStoreInterface $eventStore,
        string $executionId,
        string $activityId,
    ): bool {
        foreach ($eventStore->readStream($executionId) as $event) {
            if ($event instanceof ActivityCompleted && $event->activityId() === $activityId) {
                return true;
            }
            if ($event instanceof ActivityFailed && $event->activityId() === $activityId) {
                // Un échec en `InProgress` est journalisé lorsque le retry est délégué au transport
                // (serveur Temporal) : la tentative suivante doit réellement s'exécuter, pas être
                // court-circuitée par cet événement.
                if (ActivityRetryState::InProgress === $event->retryState()) {
                    continue;
                }

                return true;
            }
            if ($event instanceof ActivityCatastrophicFailure && $event->activityId() === $activityId) {
                return true;
            }
            if ($event instanceof ActivityCancelled && $event->activityId() === $activityId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Returns true if an ActivityTaskStarted event for the given attempt already exists in the
     * journal. Used by ActivityMessageProcessor to avoid recording duplicate task-start events
     * on re-delivery.
     */
    public static function hasActivityTaskStartedForAttempt(
        EventStoreInterface $eventStore,
        string $executionId,
        string $activityId,
        int $attempt,
    ): bool {
        foreach ($eventStore->readStream($executionId) as $event) {
            if ($event instanceof ActivityTaskStarted
                && $event->activityId() === $activityId
                && $event->attempt() === $attempt
            ) {
                return true;
            }
        }

        return false;
    }
}
