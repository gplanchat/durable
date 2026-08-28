<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

/**
 * Les handlers de query d'une exécution, tenus par le moteur.
 *
 * Ils vivaient sur {@see \Gplanchat\Durable\WorkflowEnvironment} — l'objet que le moteur avait
 * sous la main, pas celui qui en avait besoin. Un auteur de workflow pouvait donc enregistrer,
 * sonder et invoquer un handler, c'est-à-dire court-circuiter la déclaration `#[AsQueryMethod]`
 * qu'il est censé écrire.
 *
 * Le registre est porté par {@see \Gplanchat\Durable\ExecutionContext}, qu'un workflow ne reçoit
 * jamais. Le chargeur de définitions y écrit au moment d'instancier la classe ; le worker y lit
 * quand une query arrive du serveur. Ni l'un ni l'autre ne passe par l'environnement.
 *
 * @internal
 */
final class QueryHandlerRegistry
{
    /** @var array<string, callable> nom de query → handler */
    private array $handlers = [];

    public function register(string $queryType, callable $handler): void
    {
        $this->handlers[$queryType] = $handler;
    }

    public function has(string $queryType): bool
    {
        return isset($this->handlers[$queryType]);
    }

    /**
     * @param array<mixed> $args
     *
     * @throws \InvalidArgumentException si aucun handler n'est déclaré pour ce nom
     */
    public function call(string $queryType, array $args = []): mixed
    {
        $handler = $this->handlers[$queryType] ?? null;
        if (null === $handler) {
            throw new \InvalidArgumentException(\sprintf('No query handler registered for query type: %s', $queryType));
        }

        return $handler(...$args);
    }
}
