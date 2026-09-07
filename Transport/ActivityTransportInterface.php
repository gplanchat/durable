<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Transport port for activity messages.
 *
 * @see DUR002 (CQRS repositories, ports around the event journal)
 */
interface ActivityTransportInterface
{
    public function enqueue(ActivityMessage $message): void;

    public function dequeue(): ?ActivityMessage;

    /** True when no message is **ready**; a deferred message may still be pending. */
    public function isEmpty(): bool;

    /**
     * Due time of the next pending message, deferred ones included, or null if the queue is
     * truly empty.
     *
     * Distinct from {@see isEmpty()}: a synchronous drain has to know how to wait for a retry
     * scheduled later, instead of concluding that there is nothing left to do.
     */
    public function nextDueAt(): ?float;

    /**
     * Removes a message still queued for this execution and this activityId (not a dequeue).
     * Best effort: Messenger, or a queue already consumed → false.
     */
    public function removePendingFor(string $executionId, string $activityId): bool;
}
