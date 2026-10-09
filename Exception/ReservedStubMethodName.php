<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A workflow declares a signal, query or update method under a name the workflow stub keeps for
 * its own verbs, so that method could never be reached through the stub. Rename it in the workflow.
 */
final class ReservedStubMethodName extends \LogicException implements ExceptionInterface
{
    /**
     * @param class-string $workflowClass
     */
    public function __construct(
        public readonly string $workflowClass,
        public readonly string $method,
    ) {
        parent::__construct('A signal, query or update method uses a name the workflow stub keeps.');
    }
}
