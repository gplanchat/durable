<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Query;

use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Exception\WorkflowContinuedWithoutSuccessor;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowQueryNotFound;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\WorkflowMetadataStore;
use Gplanchat\Durable\WorkflowRegistry;

/**
 * Answers a query on a journal backend: the latest run of the execution is rebuilt by replaying
 * its journal to its current suspension point, with a command buffer whose methods drop every
 * command, and the `#[AsQueryMethod]` handler is called on the instance that replay built.
 * Determinism makes the state reached equal to the live one. The pass appends no event.
 *
 * It costs a replay, proportional to the length of the history.
 */
final class ReadOnlyReplay
{
    public function __construct(
        private readonly ExecutionEngine $engine,
        private readonly EventStoreInterface $events,
        private readonly WorkflowMetadataStore $metadata,
        private readonly WorkflowRegistry $registry,
    ) {}

    /**
     * @param array<mixed> $arguments
     *
     * @throws WorkflowExecutionNotFound            if no start payload is held for the run
     * @throws WorkflowContinuedWithoutSuccessor    if a run continued as new and names no successor
     * @throws WorkflowQueryNotFound                if the workflow declares no query under that name
     */
    public function query(ExecutionId $executionId, string $name, array $arguments = []): mixed
    {
        $runId = $this->latestRun($executionId);
        $row = $this->metadata->get($runId) ?? throw new WorkflowExecutionNotFound($executionId->toString());

        $handlers = $this->engine->replayWithoutWriting($runId, $this->registry->getHandler($row['workflowType'], $row['payload']))->queryHandlers();
        if (!$handlers->has($name)) {
            throw new WorkflowQueryNotFound($executionId->toString(), $name);
        }

        return $handlers->call($name, $arguments);
    }

    /**
     * Follows the continue-as-new chain, as Temporal does for a query: the latest run answers.
     */
    private function latestRun(ExecutionId $executionId): ExecutionId
    {
        while (true) {
            foreach ($this->events->readStream($executionId) as $event) {
                if ($event instanceof WorkflowContinuedAsNew) {
                    $executionId = $event->newExecutionId() ?? throw new WorkflowContinuedWithoutSuccessor($executionId->toString());

                    continue 2;
                }
            }

            return $executionId;
        }
    }
}
