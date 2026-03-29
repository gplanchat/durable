<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Port de transport des messages d'activité.
 *
 * @see ADR004 Ports et Adapters
 */
interface ActivityTransportInterface
{
    public function enqueue(ActivityMessage $message): void;

    public function dequeue(): ?ActivityMessage;

    public function isEmpty(): bool;

    /**
     * Retire un message encore en file pour cette exécution et cet activityId (non dequeue).
     * Best effort : Messenger ou file déjà consommée → false.
     */
    public function removePendingFor(string $executionId, string $activityId): bool;
}
