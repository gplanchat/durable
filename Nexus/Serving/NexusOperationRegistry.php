<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;

/**
 * Les opérations Nexus que ce composant sert.
 *
 * Une opération est nommée par un couple (service, opération) : c'est ce que porte la tâche de
 * start, et c'est donc la seule clé qui permette de router sans deviner.
 *
 * Le registre ne poll rien et ne parle à personne. Il répond à une question — « qui sert ceci ? » —
 * et rend ce que le gestionnaire a répondu. La boucle de poll, le gRPC et la traduction des erreurs
 * vivent dans le worker, qui n'a besoin d'aucune de ces trois choses pour être testé.
 */
final class NexusOperationRegistry
{
    /** @var array<string, callable(mixed): NexusOperationResponse> */
    private array $handlers = [];

    /**
     * @param callable(mixed): NexusOperationResponse $handler
     */
    public function register(NexusService $service, NexusOperationName $operation, callable $handler): void
    {
        $this->handlers[self::key($service, $operation)] = $handler;
    }

    public function serves(NexusService $service, NexusOperationName $operation): bool
    {
        return isset($this->handlers[self::key($service, $operation)]);
    }

    /**
     * @throws NexusOperationNotHandledException si aucun gestionnaire n'est déclaré
     */
    public function dispatch(NexusService $service, NexusOperationName $operation, mixed $payload): NexusOperationResponse
    {
        $handler = $this->handlers[self::key($service, $operation)] ?? null;
        if (null === $handler) {
            throw new NexusOperationNotHandledException($service, $operation);
        }

        return $handler($payload);
    }

    private static function key(NexusService $service, NexusOperationName $operation): string
    {
        // Le séparateur est un octet que ni un nom de service ni un nom d'opération ne peut
        // contenir — les deux sont validés à la construction. Un simple point laisserait
        // ("a.b", "c") et ("a", "b.c") se confondre.
        return $service->name() . "\0" . $operation->name();
    }
}
