<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Debug;

/**
 * Observation optionnelle des exécutions workflow / activités (toolbar Symfony, logs, etc.).
 */
interface WorkflowExecutionObserverInterface
{
    /**
     * @param string $workflowType Type enregistré dans le WorkflowRegistry (ou libellé de secours)
     */
    public function onWorkflowRun(string $executionId, string $workflowType, bool $isResume): void;

    /**
     * Une tentative d’exécution d’activité (inclut les retries si plusieurs appels).
     *
     * @param class-string<\Throwable>|null $errorClass
     */
    public function onActivityExecuted(
        string $executionId,
        string $activityId,
        string $activityName,
        float $durationSeconds,
        bool $success,
        ?string $errorClass,
    ): void;
}
