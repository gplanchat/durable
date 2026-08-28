<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Lit un contrat Nexus : son nom de service, et le nom d'opération de chacune de ses méthodes.
 *
 * **Il descend les interfaces parentes**, là où {@see \Gplanchat\Durable\Activity\ActivityContractResolver}
 * les ignore. Ce n'est pas une divergence gratuite : un contrat Nexus se sépare en deux, celui que
 * le gestionnaire implémente — les opérations auxquelles il répond tout de suite — et celui qui
 * l'étend pour l'appelant. C'est cette séparation qui évite d'écrire des méthodes vides pour les
 * opérations qu'un workflow remplit. Sauter les méthodes héritées ferait disparaître de la vue de
 * l'appelant les opérations déclarées sur le contrat servi : déclarées, servies, et introuvables.
 */
final class NexusContractResolver
{
    private const CACHE_PREFIX = 'durable.nexus_contract.';
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly ?CacheItemPoolInterface $cache = null,
    ) {}

    /**
     * @param class-string $contract
     *
     * @throws \LogicException si le contrat ne déclare pas son nom de service
     */
    public function serviceName(string $contract): string
    {
        $attributes = (new \ReflectionClass($contract))->getAttributes(AsNexusService::class);
        if ([] === $attributes) {
            throw new \LogicException(\sprintf(
                'Nexus contract "%s" declares no service name: add #[AsNexusService(\'…\')]. There is no fallback — the service name is what addresses an incoming task, and a name derived from the interface would be one the caller\'s endpoint never matches.',
                $contract,
            ));
        }

        return $attributes[0]->newInstance()->name;
    }

    /**
     * @param class-string $contract
     *
     * @return array<string, string> nom de méthode => nom d'opération
     *
     * @throws \LogicException si deux méthodes réclament le même nom d'opération
     */
    public function operations(string $contract): array
    {
        $key = self::CACHE_PREFIX . str_replace('\\', '_', $contract);

        if (null !== $this->cache) {
            $item = $this->cache->getItem($key);
            if ($item->isHit()) {
                return $item->get();
            }
        }

        $operations = $this->readOperations($contract);

        if (null !== $this->cache) {
            $item = $this->cache->getItem($key);
            $item->set($operations);
            $item->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);
        }

        return $operations;
    }

    /**
     * @param class-string $contract
     *
     * @return array<string, string>
     */
    private function readOperations(string $contract): array
    {
        $operations = [];
        $seen = [];

        // Sans filtre sur la classe déclarante : les méthodes héritées comptent, c'est le point.
        foreach ((new \ReflectionClass($contract))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            $attributes = $method->getAttributes(AsNexusOperation::class);
            if ([] === $attributes) {
                continue;
            }

            $name = $attributes[0]->newInstance()->name;
            if (isset($seen[$name])) {
                throw new \LogicException(\sprintf(
                    'Nexus contract "%s" declares operation "%s" twice, on %s() and %s(). Routing is by (service, operation) and nothing else, so one of the two would never be called.',
                    $contract,
                    $name,
                    $seen[$name],
                    $method->getName(),
                ));
            }

            $seen[$name] = $method->getName();
            $operations[$method->getName()] = $name;
        }

        return $operations;
    }
}
