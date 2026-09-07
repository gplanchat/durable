<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Nexus\Serving\NexusContractResolver;
use Gplanchat\Durable\Stub\StubArguments;

/**
 * Caller-side scheduling proxy.
 *
 * Exposes only the contract's methods marked {@see \Gplanchat\Durable\Attribute\AsNexusOperation};
 * every call returns an {@see Awaitable} and delegates to the scheduling port.
 *
 * The contract is the **same object** on both sides of the boundary: the handler implements the
 * served interface, the caller reads the one that extends it. The service name and the operation
 * names are therefore written only once, in the contract, and not once on each side.
 *
 * The endpoint, for its part, stays a parameter of the stub and not of the contract: it says
 * *where* the service is served, which is a deployment matter and changes from one environment to
 * another, while the contract does not change.
 *
 * @template TContract of object
 */
final class NexusStub
{
    /** @var array<string, string> method name => operation name */
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
        return StubArguments::toPayload(new \ReflectionMethod($this->contractClass, $methodName), $arguments);
    }
}
