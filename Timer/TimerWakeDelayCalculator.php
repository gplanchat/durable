<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Timer;

use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Délai jusqu’au prochain minuteur non complété (pour {@see \Symfony\Component\Messenger\Stamp\DelayStamp}).
 */
/*
 * Descendu du paquet du bundle vers le cœur : il n'importait rien de Symfony — seulement les
 * événements de minuterie et le port du magasin d'événements — et `InMemoryWorkflowRunner`, qui est
 * du cœur, l'appelait. Un hôte sans le bundle prenait donc une erreur fatale à la première reprise
 * qui devait sauter au prochain minuteur, et sous Symfony rien ne se voyait.
 */
final class TimerWakeDelayCalculator
{
    /**
     * @return int millisecondes jusqu’à {@see TimerScheduled::scheduledAt()} du prochain timer en attente
     *             (ni complété ni annulé), ou null si aucun
     */
    public static function millisecondsUntilNextTimerDue(EventStoreInterface $store, string $executionId, float $nowSeconds): ?int
    {
        $scheduled = [];
        $completed = [];
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof TimerScheduled) {
                $scheduled[$event->timerId()] = $event->scheduledAt();
            }
            if ($event instanceof TimerCompleted || $event instanceof TimerCancelled) {
                $completed[$event->timerId()] = true;
            }
        }

        $pending = [];
        foreach ($scheduled as $id => $at) {
            if (!isset($completed[$id])) {
                $pending[] = $at;
            }
        }

        if ([] === $pending) {
            return null;
        }

        $minDue = min($pending);
        $sec = max(0.0, $minDue - $nowSeconds);

        return (int) ceil($sec * 1000.0);
    }
}
