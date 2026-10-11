<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A repository class carries no `#[AsWorkflowRepository]`, so the workflow it serves is unknown.
 * The attribute is read on the class itself and is not inherited: a subclass of a declared
 * repository declares it again.
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
