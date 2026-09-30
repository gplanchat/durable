<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

use Gplanchat\Durable\ExecutionId;

/**
 * Queues nothing: activities executed elsewhere (e.g. native Temporal worker with mirror interpreter).
 */
final readonly class NoopActivityTransport implements ActivityTransportInterface
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

    public function removePendingFor(ExecutionId $executionId, string $activityId): bool
    {
        return false;
    }
}
