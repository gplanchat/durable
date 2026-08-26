<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\ExecutionContext;

/**
 * Le port d'ordonnancement, câblé sur le contexte d'exécution.
 *
 * Construit par {@see \Gplanchat\Durable\WorkflowEnvironment::activityStub()} et jamais rendu :
 * c'est ce qui fait qu'un auteur de workflow ne peut pas l'atteindre. Le contexte, lui, expose
 * bien `activity()` — mais un workflow ne reçoit jamais le contexte, il reçoit l'environnement.
 *
 * @internal
 */
final class ContextActivityScheduler implements ActivitySchedulerInterface
{
    public function __construct(
        private readonly ExecutionContext $context,
    ) {}

    public function scheduleActivity(string $activityName, array $payload, ?ActivityOptions $options): Awaitable
    {
        return $this->context->activity($activityName, $payload, $options);
    }
}
