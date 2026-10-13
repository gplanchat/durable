<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A signal, an update, a cancel or a terminate reached an execution that has ended. Its outcome is
 * unchanged.
 */
final class WorkflowExecutionAlreadyEnded extends \RuntimeException implements ExceptionInterface
{
    public function __construct(public readonly string $executionId, ?\Throwable $previous = null)
    {
        parent::__construct('The workflow execution has already ended.', 0, $previous);
    }
}
