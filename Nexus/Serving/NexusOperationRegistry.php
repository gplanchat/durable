<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;

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
     * Les opérations qu'un workflow remplit, déclarées plutôt qu'écrites.
     *
     * @var array<string, string> clé (service, opération) => type de workflow
     */
    private array $fulfilments = [];

    /**
     * Le backend qui refuse, ou `null` quand il sait router.
     *
     * Le garde est **ici**, dans le cœur, et non seulement dans la passe de compilation du bundle
     * Symfony : celle-ci n'attrape que Symfony, alors que le module Magento et le pont Illuminate
     * montent leurs services autrement et n'auraient rien eu. Un hôte qui oublie de garder est
     * précisément celui dont l'utilisateur découvrira le silence en production.
     */
    private function __construct(
        private readonly ?string $refusingBackend,
    ) {}

    /**
     * Un backend qui sait router une opération Nexus vers l'endpoint qui la sert.
     */
    public static function routedBy(string $backend): self
    {
        unset($backend);

        return new self(null);
    }

    /**
     * Un backend qui n'a aucune route, et le dit dès qu'on lui déclare un gestionnaire.
     */
    public static function unavailableOn(string $backend): self
    {
        return new self($backend);
    }

    /**
     * @param callable(mixed): NexusOperationResponse $handler
     */
    public function register(NexusService $service, NexusOperationName $operation, callable $handler): void
    {
        if (null !== $this->refusingBackend) {
            throw NexusUnsupportedByBackendException::forHandlerOn($this->refusingBackend);
        }

        $this->handlers[self::key($service, $operation)] = $handler;
    }

    public function serves(NexusService $service, NexusOperationName $operation): bool
    {
        $key = self::key($service, $operation);

        return isset($this->handlers[$key]) || isset($this->fulfilments[$key]);
    }

    /**
     * Déclare qu'un workflow remplit une opération : son résultat deviendra celui de l'opération.
     *
     * Il n'y a pas de gestionnaire à appeler, et pas de corps à écrire. Le worker démarre ce
     * workflow avec le `callback` de la tâche attaché, et le serveur livre son résultat à
     * l'appelant — c'est ce que la sonde §3.1 a mesuré, et le jeton n'y est qu'un identifiant.
     */
    public function registerFulfilment(NexusService $service, NexusOperationName $operation, string $workflowType): void
    {
        if (null !== $this->refusingBackend) {
            throw NexusUnsupportedByBackendException::forHandlerOn($this->refusingBackend);
        }

        if ('' === trim($workflowType)) {
            throw new \InvalidArgumentException('A Nexus operation fulfilled by a workflow needs a workflow type.');
        }

        $this->fulfilments[self::key($service, $operation)] = $workflowType;
    }

    /**
     * @throws NexusOperationNotHandledException si aucun gestionnaire n'est déclaré
     */
    public function dispatch(NexusService $service, NexusOperationName $operation, mixed $payload): NexusOperationResponse
    {
        $key = self::key($service, $operation);

        $workflowType = $this->fulfilments[$key] ?? null;
        if (null !== $workflowType) {
            // Déclarée plutôt qu'écrite : la charge de l'appelant devient l'entrée du workflow,
            // telle quelle. Aucun gestionnaire n'est appelé, et il n'y en a pas à écrire.
            return NexusOperationResponse::fulfilledByWorkflow($workflowType, \is_array($payload) ? $payload : ['payload' => $payload]);
        }

        $handler = $this->handlers[$key] ?? null;
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
