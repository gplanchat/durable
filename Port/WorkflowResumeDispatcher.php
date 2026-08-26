<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Port pour dispatcher la reprise d'un workflow (mode distribué).
 *
 * @see DUR021 Symfony Messenger integration (distributed resume)
 */
interface WorkflowResumeDispatcher
{
    /**
     * @param list<array{name: string, arguments: array<string, mixed>}> $pendingUpdates updates à
     *        remettre à l'exécution pour la passe déclenchée par cette reprise
     */
    public function dispatchResume(string $executionId, array $pendingUpdates = []): void;

    /**
     * Démarre un nouveau run (historique vierge) après continue-as-new ou équivalent.
     *
     * @param array<string, mixed> $payload
     */
    public function dispatchNewWorkflowRun(string $executionId, string $workflowType, array $payload): void;
}
