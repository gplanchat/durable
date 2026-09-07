<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Queues nothing: activities executed elsewhere (e.g. native Temporal worker with mirror interpreter).
 */
final class NoopActivityTransport implements ActivityTransportInterface
{
    public function enqueue(ActivityMessage $message): void {}

    public function dequeue(): ?ActivityMessage
    {
        return null;
    }

    public function nextDueAt(): ?float
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
