<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;

/**
 * No-op heartbeat sender for the in-memory backend and test contexts.
 * Never signals cancellation.
 */
final class NullActivityHeartbeatSender implements ActivityHeartbeatSenderInterface
{
    public function sendHeartbeat(mixed $details = null): bool
    {
        return false;
    }

    public function isCancellationRequested(): bool
    {
        return false;
    }
}
