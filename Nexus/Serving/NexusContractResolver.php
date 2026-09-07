<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Attribute\AsNexusOperation;
use Gplanchat\Durable\Attribute\AsNexusService;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Reads a Nexus contract: its service name, and the operation name of each of its methods.
 *
 * **It walks down parent interfaces**, where {@see \Gplanchat\Durable\Activity\ActivityContractResolver}
 * ignores them. This is not a gratuitous divergence: a Nexus contract splits in two, the one the
 * handler implements — the operations it answers straight away — and the one that extends it for
 * the caller. It is that separation which avoids writing empty methods for the operations a
 * workflow fulfils. Skipping inherited methods would make the operations declared on the served
 * contract vanish from the caller's view: declared, served, and nowhere to be found.
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
     * @throws \LogicException if the contract does not declare its service name
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
     * @return array<string, string> method name => operation name
     *
     * @throws \LogicException if two methods claim the same operation name
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

        // No filter on the declaring class: inherited methods count, that is the whole point.
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
