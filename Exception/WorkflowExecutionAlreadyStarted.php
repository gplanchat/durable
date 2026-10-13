<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A start under an execution id that cannot start again. The id reuse policy decides whether an
 * ended execution's id can start again. The execution that started first is unchanged.
 */
final class WorkflowExecutionAlreadyStarted extends \RuntimeException implements ExceptionInterface
{
    public function __construct(public readonly string $executionId)
    {
        parent::__construct('A workflow execution is already started under this id.');
    }
}
