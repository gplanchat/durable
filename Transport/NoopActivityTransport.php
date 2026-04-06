<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Ne met rien en file : activités exécutées ailleurs (ex. worker Temporal natif avec interpréteur miroir).
 */
final class NoopActivityTransport implements ActivityTransportInterface
{
    public function enqueue(ActivityMessage $message): void
    {
    }

    public function dequeue(): ?ActivityMessage
    {
        return null;
    }

    public function isEmpty(): bool
    {
        return true;
    }

    public function removePendingFor(string $executionId, string $activityId): bool
    {
        return false;
    }
}
