<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

/**
 * Port pour persister les métadonnées de workflow (type, payload) nécessaires au re-dispatch.
 *
 * Après succès, la ligne est conservée avec {@see markCompleted} pour que le type reste consultable
 * (profiler, observabilité) ; les reprises {@see WorkflowRunHandler} ignorent les lignes terminées.
 *
 * @see DUR021 Symfony Messenger integration (distributed resume)
 */
interface WorkflowMetadataStore
{
    /**
     * @param array<string, mixed> $payload
     */
    public function save(string $executionId, string $workflowType, array $payload): void;

    /**
     * Marque l’exécution comme terminée avec succès sans supprimer le type ni le payload initial.
     */
    public function markCompleted(string $executionId): void;

    /**
     * @return array{workflowType: string, payload: array<string, mixed>, completed?: bool}|null
     */
    public function get(string $executionId): ?array;

    /**
     * True tant qu’une exécution peut encore être reprise (suspendue ou en cours), pas encore {@see markCompleted}.
     */
    public function hasActiveWorkflowMetadata(string $executionId): bool;

    public function delete(string $executionId): void;
}
