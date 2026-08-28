<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\ExecutionContext;

/**
 * Le port d'ordonnancement Nexus, câblé sur le contexte d'exécution.
 *
 * Construit par {@see \Gplanchat\Durable\WorkflowEnvironment::nexusStub()} et jamais rendu. Le
 * contexte, lui, expose bien `nexusOperation()` — mais un workflow ne reçoit jamais le contexte.
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
