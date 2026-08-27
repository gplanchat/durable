<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * Le côté écriture de l'observation d'un run (DUR037).
 *
 * Le catalogue répond « quelles exécutions existent, et ce qu'elles sont devenues » ; ce port est
 * ce qui le lui apprend. Les deux sont séparés parce que le tableau de bord n'a aucune raison de
 * pouvoir écrire, et parce qu'un backend peut très bien alimenter une projection qu'il ne lit pas.
 *
 * Deux méthodes, et c'est tout ce que les décorateurs
 * {@see \Gplanchat\Durable\Store\ProjectingEventStore} et
 * {@see \Gplanchat\Durable\Store\ProjectingWorkflowMetadataStore} appellent. Elles portaient déjà
 * ces noms côté SQL avant d'être une interface — l'extraire n'a rien renommé.
 *
 * @see DUR037
 */
interface WorkflowRunProjectionInterface
{
    /**
     * Une exécution démarre. Le **nom** ne peut venir que du magasin de métadonnées :
     * `ExecutionStarted` ne porte pas le type de workflow.
     */
    public function recordStart(string $executionId, string $workflowType): void;

    /**
     * Ce que l'exécution est devenue. Vient du journal, seul endroit où l'issue est un fait.
     */
    public function recordOutcome(string $executionId, WorkflowRunStatus $status): void;
}
