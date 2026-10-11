<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A repository or a registration names a workflow class that cannot be loaded. Check the class
 * name given to `#[AsWorkflowRepository]` and the autoload configuration.
 */
final class WorkflowClassNotFound extends \LogicException implements ExceptionInterface
{
    /**
     * @param class-string $workflowClass
     */
    private function __construct(public readonly string $workflowClass, \ReflectionException $previous)
    {
        parent::__construct('The workflow class cannot be loaded.', 0, $previous);
    }

    /**
     * @param class-string $workflowClass
     */
    public static function fromReflection(string $workflowClass, \ReflectionException $previous): self
    {
        return new self($workflowClass, $previous);
    }
}
