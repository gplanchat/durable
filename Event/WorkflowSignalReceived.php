<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Signal received by the execution. The order in the journal is the order of application: each
 * signal is handed to its handler at its rank, one by one.
 */
final readonly class WorkflowSignalReceived implements Event
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private string $executionId,
        private string $signalName,
        private array $payload,
    ) {}

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function signalName(): string
    {
        return $this->signalName;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'signalName' => $this->signalName,
            'signalPayload' => $this->payload,
        ];
    }

    /**
     * Business arguments of the signal (distinct from the persistence {@see Event::payload()}).
     *
     * @return array<string, mixed>
     */
    public function signalPayload(): array
    {
        return $this->payload;
    }
}
