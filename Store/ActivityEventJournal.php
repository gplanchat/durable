<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;

/**
 * Détecte si le journal contient déjà une issue terminale pour une activité donnée
 * (succès, échec définitif, annulation). Utilisé pour ignorer les redélivrances Messenger
 * ou les doublons de traitement sans dupliquer les événements.
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
}
