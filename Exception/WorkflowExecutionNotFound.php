<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The run catalogue has no execution under this id.
 */
final class WorkflowExecutionNotFound extends \RuntimeException implements ExceptionInterface
{
    public function __construct(public readonly string $executionId)
    {
        parent::__construct('No workflow execution exists under this id.');
    }
}
