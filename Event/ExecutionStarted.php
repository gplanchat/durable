<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

final readonly class ExecutionStarted implements Event
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        private ExecutionId $executionId,
        private array $payload = [],
    ) {}

    public function executionId(): string
    {
        return $this->executionId->toString();
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->payload;
    }
}
