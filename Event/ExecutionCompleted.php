<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

final readonly class ExecutionCompleted implements Event
{
    public function __construct(
        private ExecutionId $executionId,
        private mixed $result = null,
    ) {}

    public function executionId(): ExecutionId
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
