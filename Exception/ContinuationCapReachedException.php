<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The execution's continue-as-new chain is longer than the in-memory runner's cap: each run of the
 * chain gets its own budget, so only the cap stops a workflow that always continues as new (#888).
 *
 * A {@see WorkflowStuckException}: a `catch` on a stuck execution catches this one too.
 */
final class ContinuationCapReachedException extends WorkflowStuckException
{
    public function __construct(string $executionId, int $maxContinuations)
    {
        parent::__construct($executionId, \sprintf(
            'Workflow %s continued as new more often than maxContinuations (%d) allows. '
            . 'Give the workflow a run that returns, or raise the runner\'s maxContinuations.',
            $executionId,
            $maxContinuations,
        ));
    }
}
