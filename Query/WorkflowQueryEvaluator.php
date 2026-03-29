<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Query;

use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Lectures « query » côté client : sans exécuter le code workflow, à partir du journal seul.
 *
 * Les queries synchrones Temporal ne mutent pas l’historique ; ce service expose des projections
 * courantes pour l’observabilité et les tests.
 */
final class WorkflowQueryEvaluator
{
    /**
     * Dernier résultat {@see ExecutionCompleted} présent dans le flux (null si aucun).
     */
    public static function lastExecutionResult(EventStoreInterface $store, string $executionId): mixed
    {
        $last = null;
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof ExecutionCompleted) {
                $last = $event->result();
            }
        }

        return $last;
    }

    /**
     * @return list<array{name: string, payload: array<string, mixed>}>
     */
    public static function signalsReceived(EventStoreInterface $store, string $executionId): array
    {
        $out = [];
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof WorkflowSignalReceived) {
                $out[] = [
                    'name' => $event->signalName(),
                    'payload' => $event->signalPayload(),
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<array{name: string, arguments: array<string, mixed>, result: mixed}>
     */
    public static function updatesHandled(EventStoreInterface $store, string $executionId): array
    {
        $out = [];
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof WorkflowUpdateHandled) {
                $out[] = [
                    'name' => $event->updateName(),
                    'arguments' => $event->arguments(),
                    'result' => $event->result(),
                ];
            }
        }

        return $out;
    }
}
