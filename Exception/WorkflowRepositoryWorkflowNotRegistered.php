<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A repository, declared or asked for with `#[RepositoryFor]`, serves a workflow class that the
 * host did not register, so no execution it starts could ever run. Register the workflow class (on Symfony, `#[AsWorkflow]` on an autoconfigured
 * class) or remove the repository.
 */
final class WorkflowRepositoryWorkflowNotRegistered extends \LogicException implements ExceptionInterface
{
    /**
     * @param class-string $requestedBy the declared repository, or the class whose parameter asks for one
     * @param class-string $workflowClass
     */
    public function __construct(
        public readonly string $requestedBy,
        public readonly string $workflowClass,
    ) {
        parent::__construct('A workflow repository serves a workflow that is not registered.');
    }
}
