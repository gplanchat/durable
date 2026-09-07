<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

use Gplanchat\Durable\Attribute\AsActivity;
use Gplanchat\Durable\Attribute\AsActivityMethod;
use Psr\Cache\CacheItemPoolInterface;

/**
 * Resolves an activity contract's metadata (name per method) from the attributes.
 *
 * Uses a PSR-6 cache to avoid reflection on the hot path.
 */
final class ActivityContractResolver
{
    private const CACHE_PREFIX = 'durable.activity_contract.';
    private const CACHE_TTL = 3600;

    public function __construct(
        private readonly ?CacheItemPoolInterface $cache = null,
    ) {}

    /**
     * @param class-string $contractClass
     *
     * @return array<string, string> Map methodName => activityName
     */
    public function resolveActivityMethods(string $contractClass): array
    {
        $cacheKey = self::CACHE_PREFIX . str_replace('\\', '_', $contractClass);

        if (null !== $this->cache) {
            $item = $this->cache->getItem($cacheKey);
            if ($item->isHit()) {
                return $item->get();
            }
        }

        $result = $this->resolveViaReflection($contractClass);

        if (null !== $this->cache) {
            $item = $this->cache->getItem($cacheKey);
            $item->set($result);
            $item->expiresAfter(self::CACHE_TTL);
            $this->cache->save($item);
        }

        return $result;
    }

    /**
     * @param class-string $contractClass
     *
     * @return array<string, string>
     */
    private function resolveViaReflection(string $contractClass): array
    {
        $reflection = new \ReflectionClass($contractClass);
        $activityPrefixName = null;
        $activityAttrs = $reflection->getAttributes(AsActivity::class);
        if ([] !== $activityAttrs) {
            $activityPrefixName = $activityAttrs[0]->newInstance()->name;
        }

        $methods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== $contractClass) {
                continue;
            }
            $attrs = $method->getAttributes(AsActivityMethod::class);
            if ([] === $attrs) {
                continue;
            }
            $activityMethod = $attrs[0]->newInstance();
            $activityName = $activityMethod->name;
            if (null !== $activityPrefixName && '' !== $activityPrefixName) {
                $activityName = $activityPrefixName . '.' . $activityName;
            }
            $methods[$method->getName()] = $activityName;
        }

        return $methods;
    }
}
