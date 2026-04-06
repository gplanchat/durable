<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Cooperative activity heartbeat sender.
 *
 * Activity handlers call this at regular intervals to:
 *  1. Signal Temporal that the activity is still alive (prevents heartbeat timeout).
 *  2. Check if Temporal has requested cancellation.
 *
 * Replaces the pcntl_fork-based TemporalActivityHeartbeatFork mechanism (which is forbidden).
 * The heartbeat is sent synchronously (cooperative, not background) — the activity handler
 * is responsible for calling sendHeartbeat() at appropriate checkpoints.
 *
 * @see DUR027 Activity heartbeat cooperative model
 */
interface ActivityHeartbeatSenderInterface
{
    /**
     * Sends a heartbeat to the backend and returns whether cancellation was requested.
     *
     * @param mixed $details Optional progress details to send with the heartbeat.
     *
     * @return bool True if the activity has been requested to cancel, false otherwise.
     */
    public function sendHeartbeat(mixed $details = null): bool;

    /**
     * Returns the last known cancellation state without sending a heartbeat.
     *
     * True only if a previous sendHeartbeat() call received a cancel_requested=true response.
     */
    public function isCancellationRequested(): bool;
}
