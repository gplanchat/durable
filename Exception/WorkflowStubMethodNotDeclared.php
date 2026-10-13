<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A call on a workflow stub names a method the workflow does not declare with `#[AsSignalMethod]`,
 * `#[AsQueryMethod]` or `#[AsUpdateMethod]`. Call a declared method, or declare this one.
 */
final class WorkflowStubMethodNotDeclared extends \BadMethodCallException implements ExceptionInterface
{
    /**
     * @param class-string $workflowClass
     */
    public function __construct(
        public readonly string $workflowClass,
        public readonly string $method,
    ) {
        parent::__construct('The workflow does not declare this method as a signal, query or update.');
    }
}
