<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Psr\Container\ContainerInterface;

final class RegistryActivityExecutor implements ActivityExecutor
{
    /** @var array<string, callable(array<string, mixed>): mixed> */
    private array $handlers = [];

    /**
     * @param ContainerInterface|null $lazyHandlers gestionnaires indexés par nom d'activité, résolus
     *                                             à l'appel et non à la construction
     */
    public function __construct(
        private readonly ?ContainerInterface $lazyHandlers = null,
    ) {}

    /**
     * Enregistrement direct, pour les hôtes qui n'ont pas de conteneur de services à offrir.
     */
    public function register(string $activityName, callable $handler): void
    {
        $this->handlers[$activityName] = $handler;
    }

    public function execute(string $activityName, array $payload): mixed
    {
        $handler = $this->handlers[$activityName] ?? null;

        // Le localisateur en dernier : un enregistrement direct l'emporte, ce qui laisse un test
        // remplacer un gestionnaire sans reconstruire le conteneur.
        if (null === $handler && $this->lazyHandlers?->has($activityName)) {
            $handler = $this->lazyHandlers->get($activityName);
        }

        if (null === $handler) {
            throw new \RuntimeException(\sprintf('No handler registered for activity "%s"', $activityName));
        }

        return $handler($payload);
    }
}
