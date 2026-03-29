<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

final readonly class TimerCompleted implements Event
{
    public function __construct(
        private string $executionId,
        private string $timerId,
    ) {
    }

    public function executionId(): string
    {
        return $this->executionId;
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
