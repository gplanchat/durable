<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\ExecutionContext;

/**
 * Le port de démarrage d'enfant, câblé sur le contexte d'exécution.
 *
 * Construit par {@see \Gplanchat\Durable\WorkflowEnvironment::childWorkflowStub()} et jamais
 * rendu : un workflow ne reçoit jamais le contexte, donc il ne peut pas nommer un type d'enfant
 * par une chaîne.
 *
 * @internal
 */
final class ContextChildWorkflowScheduler implements ChildWorkflowSchedulerInterface
{
    public function __construct(
        private readonly ExecutionContext $context,
    ) {}

    public function startChildWorkflow(string $childWorkflowType, array $input, ?ChildWorkflowOptions $options): Awaitable
    {
        // `ExecutionContext::executeChildWorkflow()` planifie et rend un awaitable malgré son nom :
        // c'est l'environnement qui attendait par-dessus.
        return $this->context->executeChildWorkflow($childWorkflowType, $input, $options);
    }
}
