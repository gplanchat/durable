<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * A Nexus operation has been scheduled.
 *
 * Carries the call site — endpoint, service, operation — because that is what you look for when
 * opening a profile: which external service this execution calls, and which one was expensive.
 *
 * The identity is the scheduling `eventId`. This is not a choice: it is through it that Temporal
 * ties the terminal states back to their operation, and it is therefore the only key that lets a
 * timeline be recomposed.
 */
final readonly class NexusOperationScheduled implements Event
{
    public function __construct(
        private string $executionId,
        private int $scheduledEventId,
        private string $endpoint,
        private string $service,
        private string $operation,
    ) {}

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function scheduledEventId(): int
    {
        return $this->scheduledEventId;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function service(): string
    {
        return $this->service;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'scheduledEventId' => $this->scheduledEventId,
            'endpoint' => $this->endpoint,
            'service' => $this->service,
            'operation' => $this->operation,
        ];
    }
}
