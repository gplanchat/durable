<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\ExecutionContext;

/**
 * The Nexus scheduling port, wired onto the execution context.
 *
 * Built by {@see \Gplanchat\Durable\WorkflowEnvironment::nexusStub()} and never returned. The
 * context does expose `nexusOperation()` — but a workflow never receives the context.
 *
 * @internal
 */
final class ContextNexusOperationScheduler implements NexusOperationSchedulerInterface
{
    public function __construct(
        private readonly ExecutionContext $context,
    ) {}

    public function scheduleNexusOperation(
        NexusEndpoint $endpoint,
        NexusService $service,
        NexusOperationName $operation,
        array $payload,
        ?NexusOperationTimeouts $timeouts,
    ): Awaitable {
        return $this->context->nexusOperation($endpoint, $service, $operation, $payload, $timeouts);
    }
}
