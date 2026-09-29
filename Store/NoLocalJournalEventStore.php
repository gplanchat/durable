<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Exception\UnsupportedByBackendException;
use Gplanchat\Durable\ExecutionId;

/**
 * The event store of a backend that keeps no local journal: every call is refused (DUR051).
 *
 * The Temporal worker's runtime needs an {@see EventStoreInterface} by signature and never uses
 * it: there, the server is the journal. This store replaces `NullEventStore`, whose empty history
 * was indistinguishable from a run that has not started. A workflow given it ran its activities
 * again instead of failing; given this one, it fails with a message that says why.
 */
final readonly class NoLocalJournalEventStore implements EventStoreInterface
{
    public function __construct(
        private string $backend,
    ) {}

    public function append(Event $event): void
    {
        throw $this->refusal(__FUNCTION__);
    }

    public function readStream(ExecutionId|string $executionId): iterable
    {
        $executionId = (string) $executionId;

        throw $this->refusal(__FUNCTION__);
    }

    public function readStreamWithRecordedAt(ExecutionId|string $executionId): iterable
    {
        $executionId = (string) $executionId;

        throw $this->refusal(__FUNCTION__);
    }

    public function countEventsInStream(ExecutionId|string $executionId): int
    {
        $executionId = (string) $executionId;

        throw $this->refusal(__FUNCTION__);
    }

    private function refusal(string $method): UnsupportedByBackendException
    {
        return UnsupportedByBackendException::forMethod($this->backend, $method, 'this backend keeps no local journal; its history lives on the server and is read through the backend\'s own history source.');
    }
}
