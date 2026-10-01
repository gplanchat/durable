<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The execution was terminated from outside the workflow (an operator, the CLI, a parent close
 * policy): no workflow code ran to close it.
 */
final class WorkflowTerminatedException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $reason,
    ) {
        parent::__construct(\sprintf('Workflow "%s" was terminated: %s', $executionId, $reason));
    }
}
