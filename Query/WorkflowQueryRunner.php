<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Query;

use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Injectable (DI) facade for "query" reads from the journal alone.
 *
 * @see WorkflowQueryEvaluator for the reusable static logic
 */
final class WorkflowQueryRunner
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
    ) {}

    public function lastExecutionResult(string $executionId): mixed
    {
        return WorkflowQueryEvaluator::lastExecutionResult($this->eventStore, $executionId);
    }

}
