<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

/**
 * An execution's query handlers, held by the engine.
 *
 * They used to live on {@see \Gplanchat\Durable\WorkflowEnvironment} — the object the engine had
 * to hand, not the one that needed them. A workflow author could therefore register, probe and
 * invoke a handler, that is, short-circuit the `#[AsQueryMethod]` declaration they are supposed
 * to write.
 *
 * The registry is carried by {@see \Gplanchat\Durable\ExecutionContext}, which a workflow never
 * receives. The definition loader writes to it when instantiating the class; the worker reads
 * from it when a query arrives from the server. Neither of them goes through the environment.
 *
 * @internal
 */
final class QueryHandlerRegistry
{
    /** @var array<string, callable> query name → handler */
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
     * @throws \InvalidArgumentException if no handler is declared for that name
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
