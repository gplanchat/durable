<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

final class RegistryActivityExecutor implements ActivityExecutor
{
    /** @var array<string, callable(array<string, mixed>): mixed> */
    private array $handlers = [];

    public function register(string $activityName, callable $handler): void
    {
        $this->handlers[$activityName] = $handler;
    }

    public function execute(string $activityName, array $payload): mixed
    {
        $handler = $this->handlers[$activityName] ?? null;
        if (null === $handler) {
            throw new \RuntimeException(\sprintf('No handler registered for activity "%s"', $activityName));
        }

        return $handler($payload);
    }
}
