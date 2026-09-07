<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Query;

use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * Client-side "query" reads: without running the workflow code, from the journal alone.
 *
 * Temporal's synchronous queries do not mutate the history; this service exposes common
 * projections for observability and tests.
 */
final class WorkflowQueryEvaluator
{
    /**
     * Last {@see ExecutionCompleted} result present in the stream (null if there is none).
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

    /**
     * Returns true if the execution has at least one TimerScheduled without a corresponding
     * TimerCompleted (i.e., the workflow is suspended waiting for a timer to fire).
     */
    public static function hasPendingTimer(EventStoreInterface $store, string $executionId): bool
    {
        $scheduled = [];
        foreach ($store->readStream($executionId) as $event) {
            if ($event instanceof TimerScheduled) {
                $scheduled[$event->timerId()] = true;
            } elseif ($event instanceof TimerCompleted) {
                unset($scheduled[$event->timerId()]);
            }
        }

        return [] !== $scheduled;
    }
}
