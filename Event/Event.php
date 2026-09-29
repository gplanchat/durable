<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\ExecutionId;

interface Event
{
    public function executionId(): ExecutionId;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array;
}
