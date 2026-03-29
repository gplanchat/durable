<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Query;

use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Façade injectable (DI) pour les lectures « query » à partir du journal seul.
 *
 * @see WorkflowQueryEvaluator pour la logique statique réutilisable
 */
final class WorkflowQueryRunner
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
    ) {
    }

    public function lastExecutionResult(string $executionId): mixed
    {
        return WorkflowQueryEvaluator::lastExecutionResult($this->eventStore, $executionId);
    }

    /**
     * @return list<array{name: string, payload: array<string, mixed>}>
     */
    public function signalsReceived(string $executionId): array
    {
        return WorkflowQueryEvaluator::signalsReceived($this->eventStore, $executionId);
    }

    /**
     * @return list<array{name: string, arguments: array<string, mixed>, result: mixed}>
     */
    public function updatesHandled(string $executionId): array
    {
        return WorkflowQueryEvaluator::updatesHandled($this->eventStore, $executionId);
    }
}
