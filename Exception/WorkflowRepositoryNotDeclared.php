<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A repository class carries no `#[AsWorkflowRepository]`, so it does not say which workflow it serves.
 */
final class WorkflowRepositoryNotDeclared extends \LogicException implements ExceptionInterface
{
    /**
     * @param class-string $repositoryClass
     */
    public function __construct(public readonly string $repositoryClass)
    {
        parent::__construct('A workflow repository must carry #[AsWorkflowRepository].');
    }
}
