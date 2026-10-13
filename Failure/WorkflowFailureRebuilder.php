<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Failure;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\Exception\WorkflowFailedException;

/**
 * Reads back, from the journal, the exception a failed execution raised, as the Temporal client's
 * `pollForCompletion()` raises it from the server's history. An unhandled activity failure comes
 * back as {@see DurableWorkflowAlgorithmFailureException} around the activity's own failure,
 * rebuilt from the journal's {@see ActivityFailed} when it is given, else around a
 * {@see WorkflowFailedException}. A Nexus operation failure is not rebuilt yet: the journal event
 * keeps no envelope for it, so it comes back as {@see WorkflowFailedException}.
 * A failure whose class does not load here, or does not take `(message, code)`, comes back as
 * {@see WorkflowFailedException}.
 *
 * @internal
 */
final class WorkflowFailureRebuilder
{
    private function __construct() {}

    /**
     * @param ActivityFailed|null $activityFailure the journal's event for the activity an unhandled
     *                                             activity failure names, when the journal holds it
     */
    public static function toThrowable(WorkflowExecutionFailed $failure, ?ActivityFailed $activityFailure = null): \Throwable
    {
        $executionId = $failure->executionId()->toString();
        $unrebuilt = new WorkflowFailedException($executionId, $failure->failureMessage());

        if (isset(WorkflowExecutionFailed::ACTIVITY_FAILURE_PREFIXES[$failure->kind()])) {
            return new DurableWorkflowAlgorithmFailureException(
                WorkflowExecutionFailed::ACTIVITY_FAILURE_PREFIXES[$failure->kind()] . $failure->failureMessage(),
                0,
                null === $activityFailure ? $unrebuilt : DurableActivityFailedException::toThrowable($activityFailure),
            );
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
