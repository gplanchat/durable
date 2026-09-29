<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;

/**
 * Local implementation of the workflow backend (EventStore + Messenger).
 *
 * Uses ExecutionEngine with the configured event store and transport.
 * No dependency on RoadRunner or Temporal.
 *
 * @see WorkflowBackendInterface
 * @see DUR021 Symfony Messenger integration
 */
final class LocalWorkflowBackend implements WorkflowBackendInterface
{
    public function __construct(
        private readonly ExecutionEngine $engine,
    ) {}

    public function start(ExecutionId $executionId, callable $handler, ?string $workflowType = null): mixed
    {
        return $this->engine->start($executionId->toString(), $handler, $workflowType);
    }
}
