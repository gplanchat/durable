<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

use Gplanchat\Durable\ExecutionId;

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

    public function removePendingFor(ExecutionId|string $executionId, string $activityId): bool
    {
        $executionId = (string) $executionId;

        return false;
    }
}
