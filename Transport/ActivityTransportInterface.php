<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Port de transport des messages d'activité.
 *
 * @see DUR002 (CQRS repositories, ports around the event journal)
 */
interface ActivityTransportInterface
{
    public function enqueue(ActivityMessage $message): void;

    public function dequeue(): ?ActivityMessage;

    /** True quand aucun message n'est **prêt** ; un message différé peut rester en attente. */
    public function isEmpty(): bool;

    /**
     * Échéance du prochain message en attente, différé compris, ou null si la file est vraiment
     * vide.
     *
     * Distinct de {@see isEmpty()} : un drain synchrone doit savoir attendre une retentative
     * planifiée plus tard, au lieu de conclure qu'il n'y a plus rien à faire.
     */
    public function nextDueAt(): ?float;

    /**
     * Retire un message encore en file pour cette exécution et cet activityId (non dequeue).
     * Best effort : Messenger ou file déjà consommée → false.
     */
    public function removePendingFor(string $executionId, string $activityId): bool;
}
