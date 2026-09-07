<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The in-memory runner cannot carry the execution through to the end.
 *
 * Signalled rather than looped on empty: a test harness must fail, not freeze.
 */
final class WorkflowStuckException extends \RuntimeException
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
}
