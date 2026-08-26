<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Failure;

use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Exception\ActivitySupersededException;
use Gplanchat\Durable\Exception\DeadlineExceededException;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableCatastrophicActivityFailureException;
use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;

/**
 * Traduit un throwable remonté d'un fiber workflow en {@see WorkflowExecutionFailed} typé.
 *
 * Point de passage unique des **deux** pilotes de fiber ({@see \Gplanchat\Durable\ExecutionEngine}
 * et {@see \Gplanchat\Bridge\Temporal\Worker\WorkflowTaskRunner}) : sans lui, le pilote Temporal
 * aplatissait tout sur un seul `kind`.
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
            $e instanceof DeadlineExceededException => WorkflowExecutionFailed::deadlineExceeded($executionId, $e),
            $e instanceof DeclaredActivityFailureInterface => WorkflowExecutionFailed::unhandledDeclaredActivityFailure($executionId, $e),
            default => WorkflowExecutionFailed::workflowHandlerFailure($executionId, $e),
        };
    }
}
