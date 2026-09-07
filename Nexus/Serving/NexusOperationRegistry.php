<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException;

/**
 * The Nexus operations this component serves.
 *
 * An operation is named by a (service, operation) pair: that is what the start task carries, and it
 * is therefore the only key that allows routing without guessing.
 *
 * The registry polls nothing and talks to nobody. It answers one question — "who serves this?" —
 * and returns what the handler answered. The polling loop, the gRPC and the error translation live
 * in the worker, which needs none of those three things to be tested.
 */
final class NexusOperationRegistry
{
    /** @var array<string, callable(mixed): NexusOperationResponse> */
    private array $handlers = [];

    /**
     * The operations a workflow fulfils, declared rather than written.
     *
     * @var array<string, string> (service, operation) key => workflow type
     */
    private array $fulfilments = [];

    /**
     * The backend that refuses, or `null` when it knows how to route.
     *
     * The guard is **here**, in the core, and not only in the Symfony bundle's compiler pass: that
     * one only catches Symfony, whereas the Magento module and the Illuminate bridge wire their
     * services differently and would have had nothing. A host that forgets to guard is precisely
     * the one whose user will discover the silence in production.
     */
    private function __construct(
        private readonly ?string $refusingBackend,
    ) {}

    /**
     * A backend that knows how to route a Nexus operation to the endpoint that serves it.
     */
    public static function routedBy(string $backend): self
    {
        unset($backend);

        return new self(null);
    }

    /**
     * A backend that has no route at all, and says so as soon as a handler is declared on it.
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
     * Declares that a workflow fulfils an operation: its result will become the operation's.
     *
     * There is no handler to call, and no body to write. The worker starts that workflow with the
     * task's `callback` attached, and the server delivers its result to the caller — that is what
     * probe §3.1 measured, and the token there is only an identifier.
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
     * @throws NexusOperationNotHandledException if no handler is declared
     */
    public function dispatch(NexusService $service, NexusOperationName $operation, mixed $payload): NexusOperationResponse
    {
        $key = self::key($service, $operation);

        $workflowType = $this->fulfilments[$key] ?? null;
        if (null !== $workflowType) {
            // Declared rather than written: the caller's payload becomes the workflow's input, as
            // it stands. No handler is called, and there is none to write.
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
        // The separator is a byte that neither a service name nor an operation name can contain —
        // both are validated at construction. A plain dot would let ("a.b", "c") and ("a", "b.c")
        // be confused with one another.
        return $service->name() . "\0" . $operation->name();
    }
}
