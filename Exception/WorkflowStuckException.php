<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The execution did not finish within the bound its caller waits for: the in-memory runner's
 * budget, or a client's polls of a cluster.
 *
 * Signalled rather than looped on empty: a test harness must fail, not freeze.
 */
final class WorkflowStuckException extends \RuntimeException implements ExceptionInterface
{
    private function __construct(
        public readonly string $executionId,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * The execution is waiting for something this runner does not produce.
     */
    public static function noProgress(string $executionId, ?string $waitingOn = null): self
    {
        return new self($executionId, \sprintf(
            'Workflow %s is suspended on something the in-memory runner cannot settle '
            . '(undelivered signal / update, or a timer that is not due)%s. '
            . 'Append the awaited event to the store before running, or use the distributed backend.',
            $executionId,
            null !== $waitingOn ? ', waiting on ' . $waitingOn : '',
        ));
    }

    /**
     * The execution is still moving forward but goes over the budget: typically an activity that
     * fails and that the default policy retries indefinitely.
     */
    public static function budgetExhausted(string $executionId, float $budgetSeconds): self
    {
        return new self($executionId, \sprintf(
            'Workflow %s did not finish within %.1fs. Activities retry indefinitely by default '
            . '(RetryLimit::unlimited(), Temporal semantics): pass RetryLimit::ofAttempts(n) or '
            . 'RetryLimit::once(), declare the exception non-retryable, or raise the runner budget.',
            $executionId,
            $budgetSeconds,
        ));
    }

    /**
     * A client polled the cluster for the close event as many times as it was allowed to, and the
     * execution is still open: no worker carries it, it waits on a signal, or it is slow (#765).
     */
    public static function pollsExhausted(string $executionId, int $maxRefreshes, int $refreshIntervalMs): self
    {
        return new self($executionId, \sprintf(
            'Workflow "%s" did not complete within %d poll attempts (%d ms interval = ~%d s total).',
            $executionId,
            $maxRefreshes,
            $refreshIntervalMs,
            (int) ($maxRefreshes * $refreshIntervalMs / 1000),
        ));
    }
}
