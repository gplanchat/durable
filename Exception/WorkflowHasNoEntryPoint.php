<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A workflow class declares no `#[AsWorkflowMethod]`, so there is nothing to describe or to run.
 * Put the attribute on the one method that holds the workflow's logic.
 */
final class WorkflowHasNoEntryPoint extends \LogicException implements ExceptionInterface
{
    /**
     * @param class-string $workflowClass
     */
    private function __construct(public readonly string $workflowClass)
    {
        parent::__construct('The workflow class declares no #[AsWorkflowMethod].');
    }

    /**
     * @param class-string $workflowClass
     */
    public static function forClass(string $workflowClass): self
    {
        return new self($workflowClass);
    }
}
