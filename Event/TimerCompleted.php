<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

final readonly class TimerCompleted implements Event
{
    public function __construct(
        private ExecutionId|string $executionId,
        private string $timerId,
    ) {}

    public function executionId(): string
    {
        return (string) $this->executionId;
    }

    public function timerId(): string
    {
        return $this->timerId;
    }

    public function payload(): array
    {
        return ['timerId' => $this->timerId];
    }
}
