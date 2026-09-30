<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Query;

use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Timer\PendingTimers;

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
        foreach ($store->readStream(ExecutionId::fromString($executionId)) as $event) {
            if ($event instanceof ExecutionCompleted) {
                $last = $event->result();
            }
        }

        return $last;
    }

    /**
     * Returns true if the execution has at least one timer that neither fired nor was cancelled,
     * i.e. the workflow is suspended waiting for a timer. `PendingTimers` is the one reading of that.
     */
    public static function hasPendingTimer(EventStoreInterface $store, string $executionId): bool
    {
        return [] !== PendingTimers::of($store, $executionId);
    }
}
