<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Nexus\Serving\NexusContractResolver;

/**
 * Proxy de planification côté appelant.
 *
 * N'expose que les méthodes marquées {@see \Gplanchat\Durable\Attribute\AsNexusOperation} du
 * contrat ; chaque appel rend un {@see Awaitable} et délègue au port d'ordonnancement.
 *
 * Le contrat est le **même objet** des deux côtés de la frontière : le gestionnaire implémente
 * l'interface servie, l'appelant lit celle qui l'étend. Le nom de service et les noms d'opération
 * ne s'écrivent donc qu'une fois, dans le contrat, et non une fois chez chacun.
 *
 * L'endpoint, lui, reste un paramètre du stub et non du contrat : il dit *où* le service est servi,
 * ce qui est une affaire de déploiement et change d'un environnement à l'autre, quand le contrat ne
 * change pas.
 *
 * @template TContract of object
 */
final class NexusStub
{
    /** @var array<string, string> nom de méthode => nom d'opération */
    private array $methodToOperation;

    private NexusService $service;

    /**
     * @param class-string<TContract> $contractClass
     */
    public function __construct(
        private readonly NexusOperationSchedulerInterface $scheduler,
        private readonly string $contractClass,
        NexusContractResolver $resolver,
        private readonly NexusEndpoint $endpoint,
        private readonly ?NexusOperationTimeouts $timeouts = null,
    ) {
        $this->methodToOperation = $resolver->operations($contractClass);
        $this->service = NexusService::named($resolver->serviceName($contractClass));
    }

    /**
     * @param array<mixed> $arguments
     *
     * @return Awaitable<mixed>
     */
    public function __call(string $name, array $arguments): Awaitable
    {
        $operation = $this->methodToOperation[$name] ?? null;
        if (null === $operation) {
            throw new \BadMethodCallException(\sprintf(
                'Method %s::%s() is not a Nexus operation (missing #[AsNexusOperation]) or does not exist.',
                $this->contractClass,
                $name,
            ));
        }

        return $this->scheduler->scheduleNexusOperation(
            $this->endpoint,
            $this->service,
            NexusOperationName::named($operation),
            $this->argumentsToPayload($name, $arguments),
            $this->timeouts,
        );
    }

    /**
     * @param array<mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function argumentsToPayload(string $methodName, array $arguments): array
    {
        $payload = [];
        foreach ((new \ReflectionMethod($this->contractClass, $methodName))->getParameters() as $i => $param) {
            $payload[$param->getName()] = $arguments[$i]
                ?? ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
        }

        return $payload;
    }
}
