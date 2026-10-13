<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Failure;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\Exception\WorkflowFailedException;

/**
 * Reads back, from the journal, the exception a failed execution raised, as the Temporal client's
 * `pollForCompletion()` raises it from the server's history. The journal keeps the class, message
 * and code of the failure, not the activity's own exception: an activity failure comes back
 * wrapped in {@see DurableWorkflowAlgorithmFailureException} around a {@see WorkflowFailedException}.
 * A failure whose class does not load here, or does not take `(message, code)`, comes back as
 * {@see WorkflowFailedException}.
 *
 * @internal
 */
final class WorkflowFailureRebuilder
{
    /** The prefix {@see \Gplanchat\Durable\Store\EventStoreWorkflowLifecycle::onFailed()} gives each. */
    private const ACTIVITY_KINDS = [
        WorkflowExecutionFailed::KIND_UNHANDLED_CATASTROPHIC_ACTIVITY => 'Workflow did not handle catastrophic activity failure: ',
        WorkflowExecutionFailed::KIND_UNHANDLED_ACTIVITY => 'Workflow did not handle activity failure: ',
        WorkflowExecutionFailed::KIND_UNHANDLED_ACTIVITY_SUPERSEDED => 'Workflow did not handle superseded activity: ',
        WorkflowExecutionFailed::KIND_UNHANDLED_DECLARED_ACTIVITY => 'Workflow did not handle declared activity failure: ',
    ];

    private function __construct() {}

    public static function toThrowable(WorkflowExecutionFailed $failure): \Throwable
    {
        $executionId = $failure->executionId()->toString();
        $unrebuilt = new WorkflowFailedException($executionId, $failure->failureMessage());

        if (isset(self::ACTIVITY_KINDS[$failure->kind()])) {
            return new DurableWorkflowAlgorithmFailureException(self::ACTIVITY_KINDS[$failure->kind()] . $failure->failureMessage(), 0, $unrebuilt);
        }

        try {
            $rebuilt = WorkflowExecutionFailed::KIND_DEADLINE_EXCEEDED === $failure->kind()
                ? new DeadlineExceededException(
                    Duration::seconds((float) ($failure->context()['deadlineSeconds'] ?? 0)),
                    (string) ($failure->context()['awaited'] ?? ''),
                )
                : self::workflowException($failure);
        } catch (\Throwable) {
            // A constructor that throws is a failure that cannot be rebuilt: the fallback below names it.
            $rebuilt = null;
        }

        return null !== $rebuilt && $rebuilt->getMessage() === $failure->failureMessage() ? $rebuilt : $unrebuilt;
    }

    private static function workflowException(WorkflowExecutionFailed $failure): ?\Throwable
    {
        $class = $failure->failureClass();
        if (!class_exists($class) || !is_subclass_of($class, \Throwable::class)) {
            return null;
        }

        return (new \ReflectionClass($class))->newInstance($failure->failureMessage(), $failure->failureCode());
    }
}
