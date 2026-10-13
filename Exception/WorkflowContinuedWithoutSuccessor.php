<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The journal of this run says it continued as new, and does not name the run that continues it
 * (a journal written before #322).
 */
final class WorkflowContinuedWithoutSuccessor extends \RuntimeException implements ExceptionInterface
{
    public function __construct(public readonly string $executionId)
    {
        parent::__construct('The run continued as new and names no successor.');
    }
}
