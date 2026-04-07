<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\ExecutionEngine;

/**
 * Implémentation locale du backend workflow (EventStore + Messenger).
 *
 * Utilise ExecutionEngine avec l'event store et le transport configurés.
 * Pas de dépendance à RoadRunner ou Temporal.
 *
 * @see WorkflowBackendInterface
 * @see DUR021 Symfony Messenger integration
 */
final class LocalWorkflowBackend implements WorkflowBackendInterface
{
    public function __construct(
        private readonly ExecutionEngine $engine,
    ) {
    }

    #[\Override]
    public function start(string $executionId, callable $handler, ?string $workflowType = null): mixed
    {
        return $this->engine->start($executionId, $handler, $workflowType);
    }
}
