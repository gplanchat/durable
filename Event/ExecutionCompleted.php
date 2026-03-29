<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

final readonly class ExecutionCompleted implements Event
{
    public function __construct(
        private string $executionId,
        private mixed $result = null,
    ) {
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function payload(): array
    {
        return ['result' => $this->result];
    }
}
