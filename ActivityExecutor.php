<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

interface ActivityExecutor
{
    public function register(string $activityName, callable $handler): void;

    /**
     * @param array<string, mixed> $payload
     */
    public function execute(string $activityName, array $payload): mixed;
}
