<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

/**
 * Adapts a worker call (array payload, keys = the contract's parameter names) onto the handler's method.
 *
 * It used to live in the Symfony bundle package, without importing a single line of it. Magento
 * needs it word for word — its container has no tags, but once the contract is resolved the
 * adaptation is the same — and copying it over would be the duplication this repository refuses
 * elsewhere. So it comes down next to {@see ActivityContractResolver}, which feeds it.
 *
 * Nexus uses it too, through {@see \Gplanchat\Durable\Nexus\Serving\NexusHandlerInvoker}: a served
 * operation and an activity pose the same problem — a payload keyed by name, a contract method to
 * call. It therefore stays in `Activity\` by its history, but its text no longer says "activity"
 * where it speaks of both.
 */
final class PayloadToContractMethodInvoker
{
    /**
     * @param class-string $contractClass
     */
    public function __construct(
        private readonly object $handler,
        private readonly string $contractClass,
        private readonly string $contractMethodName,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    public function __invoke(array $payload): mixed
    {
        $reflection = new \ReflectionMethod($this->contractClass, $this->contractMethodName);
        $args = [];
        foreach ($reflection->getParameters() as $param) {
            $key = $param->getName();
            if (\array_key_exists($key, $payload)) {
                $args[] = $payload[$key];
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new \InvalidArgumentException(\sprintf('Missing payload key "%s" for contract method %s::%s()', $key, $this->contractClass, $this->contractMethodName));
            }
        }

        $impl = new \ReflectionClass($this->handler);
        $method = $impl->getMethod($this->contractMethodName);

        return $method->invoke($this->handler, ...$args);
    }
}
