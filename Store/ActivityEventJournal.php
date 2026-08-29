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
     * Dernière issue terminale journalisée pour une activité, ou null si elle en attend encore une.
     *
     * Prend la **dernière** correspondance : un échec en {@see ActivityRetryState::InProgress} laissé
     * par une tentative précédente est ainsi supplanté par le succès de la suivante.
     */
    public static function lastTerminalOutcome(
        EventStoreInterface $eventStore,
        string $executionId,
        string $activityId,
    ): ActivityCompleted|ActivityFailed|ActivityCatastrophicFailure|ActivityCancelled|null {
        $last = null;
        foreach ($eventStore->readStream($executionId) as $event) {
            if (!$event instanceof ActivityCompleted
                && !$event instanceof ActivityFailed
                && !$event instanceof ActivityCatastrophicFailure
                && !$event instanceof ActivityCancelled
            ) {
                continue;
            }
            if ($event->activityId() === $activityId) {
                $last = $event;
            }
        }

        return $last;
    }

    /**
     * L'issue qui tranche **cette livraison-ci**, ou `null` s'il faut exécuter.
     *
     * ⚠ Cette classe portait déjà deux notions de « terminal » qui se contredisaient.
     * {@see self::hasTerminalOutcomeForActivity()} sait qu'un `ActivityFailed` en
     * {@see ActivityRetryState::InProgress} n'est pas terminal — la tentative suivante doit
     * réellement s'exécuter — mais {@see self::lastTerminalOutcome()} rend le même événement sans
     * cette réserve. Le worker Temporal interrogeait la seconde **avant** de traiter, pour ne pas
     * réexécuter une tâche redélivrée : il répondait donc au serveur l'échec de la tentative 1 pour
     * les tentatives 2 et 3, sans jamais rappeler le code de l'activité. Trois tentatives brûlées
     * en deux secondes, le même message d'échec recopié, et une panne passagère devenue définitive.
     *
     * Ce qui distingue les deux cas n'est pas la nature de l'issue mais **le rang de la tentative
     * qui l'a écrite** : une issue écrite pour la tentative en cours est une redélivrance, à
     * laquelle il faut répondre sans réexécuter ; une issue écrite pour une tentative antérieure
     * est une reprise, qui doit s'exécuter.
     *
     * Une issue autre qu'un échec en cours de reprise est terminale quel que soit son rang : une
     * activité terminée, annulée ou irrémédiablement cassée ne se rejoue pas. Un `retryState` nul —
     * journal ancien, politique non renseignée — n'est pas `InProgress` et reste donc terminal :
     * dans le doute, on ne rejoue pas un effet de bord.
     */
    public static function settledOutcomeForDelivery(
        EventStoreInterface $eventStore,
        string $executionId,
        string $activityId,
        int $attempt,
    ): ActivityCompleted|ActivityFailed|ActivityCatastrophicFailure|ActivityCancelled|null {
        $last = self::lastTerminalOutcome($eventStore, $executionId, $activityId);

        if ($last instanceof ActivityFailed
            && ActivityRetryState::InProgress === $last->retryState()
            && $last->failureAttempt() < $attempt
        ) {
            return null;
        }

        return $last;
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
