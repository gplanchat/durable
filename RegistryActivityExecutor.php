<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Psr\Container\ContainerInterface;

final class RegistryActivityExecutor implements ActivityExecutor
{
    /** @var array<string, callable(array<string, mixed>): mixed> */
    private array $handlers = [];

    /**
     * @param ContainerInterface|null $lazyHandlers handlers indexed by activity name, resolved at
     *                                             call time rather than at construction
     */
    public function __construct(
        private readonly ?ContainerInterface $lazyHandlers = null,
    ) {}

    /**
     * Direct registration, for hosts with no service container to offer.
     */
    public function register(string $activityName, callable $handler): void
    {
        $this->handlers[$activityName] = $handler;
    }

    public function execute(string $activityName, array $payload): mixed
    {
        $handler = $this->handlers[$activityName] ?? null;

        // The locator comes last: a direct registration wins, which lets a test replace a
        // handler without rebuilding the container.
        if (null === $handler && $this->lazyHandlers?->has($activityName)) {
            $handler = $this->lazyHandlers->get($activityName);
        }

        if (null === $handler) {
            throw new \RuntimeException(\sprintf('No handler registered for activity "%s"', $activityName));
        }

        return $handler($payload);
    }
}
