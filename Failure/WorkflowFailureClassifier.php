<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Failure;

use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Exception\DurableNexusOperationFailedException;
use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;

/**
 * Translates a throwable surfacing from a workflow fiber into a typed {@see WorkflowExecutionFailed}.
 *
 * Single point of passage for **both** fiber drivers ({@see \Gplanchat\Durable\ExecutionEngine}
 * and {@see \Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner}): without it, the Temporal
 * driver flattened everything onto a single `kind`.
 */
final class WorkflowFailureClassifier
{
    private function __construct() {}

    public static function classify(string $executionId, \Throwable $e): WorkflowExecutionFailed
    {
        return match (true) {
            $e instanceof DurableCatastrophicActivityFailureException => WorkflowExecutionFailed::unhandledCatastrophicActivity($executionId, $e),
            $e instanceof DurableActivityFailedException => WorkflowExecutionFailed::unhandledActivityFailure($executionId, $e->activityId(), $e->activityName(), $e),
            $e instanceof ActivitySupersededException => WorkflowExecutionFailed::unhandledActivitySuperseded($executionId, $e),
            $e instanceof DurableNexusOperationFailedException => WorkflowExecutionFailed::unhandledNexusOperationFailure($executionId, $e),
            $e instanceof DeadlineExceededException => WorkflowExecutionFailed::deadlineExceeded($executionId, $e),
            $e instanceof DeclaredActivityFailureInterface => WorkflowExecutionFailed::unhandledDeclaredActivityFailure($executionId, $e),
            default => WorkflowExecutionFailed::workflowHandlerFailure($executionId, $e),
        };
    }
}
