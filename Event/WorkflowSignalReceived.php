<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

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
        private ExecutionId $executionId,
        private string $signalName,
        private array $payload,
        /** The delivery's request id (DUR052); null on a signal journalled before it was kept. */
        private ?string $requestId = null,
    ) {}

    public function requestId(): ?string
    {
        return $this->requestId;
    }

    public function executionId(): string
    {
        return $this->executionId->toString();
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
            ...(null !== $this->requestId ? ['requestId' => $this->requestId] : []),
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
