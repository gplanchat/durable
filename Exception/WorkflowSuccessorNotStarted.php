<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The journal of a run says it continued as new and names the run that continues it, and no such
 * run exists. The backend recorded the continuation and did not start the next run (Magento's
 * in-memory runner does not), so a result, a cancel or a terminate has nothing to follow.
 */
final class WorkflowSuccessorNotStarted extends \RuntimeException implements ExceptionInterface
{
    public function __construct(public readonly string $executionId, public readonly string $successorId, ?\Throwable $previous = null)
    {
        parent::__construct('The run continued as new and its successor was never started.', 0, $previous);
    }
}
