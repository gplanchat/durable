<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A start under an execution id that the backend refuses to start again. Whether an id may start
 * again after its execution ended is the id reuse policy's call, not this exception's. The
 * execution that started first is unchanged.
 */
final class WorkflowExecutionAlreadyStarted extends \RuntimeException implements ExceptionInterface
{
    public function __construct(public readonly string $executionId)
    {
        parent::__construct('A workflow execution is already started under this id.');
    }
}
