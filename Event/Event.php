<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

interface Event
{
    public function executionId(): string;

    /**
     * @return array<string, mixed>
     */
    public function payload(): array;
}
