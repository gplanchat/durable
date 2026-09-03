<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Debug;

/**
 * L'observateur quand personne n'observe.
 *
 * L'observation est optionnelle par contrat, mais les trois services du chemin chaud —
 * {@see \Gplanchat\Durable\ExecutionRuntime}, {@see \Gplanchat\Durable\ExecutionEngine},
 * {@see \Gplanchat\Durable\Worker\ActivityMessageProcessor} — la reçoivent en injection. Il leur
 * faut donc quelqu'un, y compris en production où il n'y a pas de profileur à alimenter.
 *
 * Ne rien faire est ici un comportement réel et non un bouche-trou de signature : une exécution
 * que personne ne regarde s'exécute pareil. C'est la même raison qui fait de `Psr\Log\NullLogger`
 * un objet nul légitime.
 */
final class NullWorkflowExecutionObserver implements WorkflowExecutionObserverInterface
{
    #[\Override]
    public function onWorkflowRun(string $executionId, string $workflowType, bool $isResume): void {}

    #[\Override]
    public function onActivityExecuted(
        string $executionId,
        string $activityId,
        string $activityName,
        float $durationSeconds,
        bool $success,
        ?string $errorClass,
    ): void {}
}
