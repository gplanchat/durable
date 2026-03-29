<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Port pour les backends de workflow (ex. implémentation locale, Temporal).
 *
 * Cette interface permet d'abstraire le démarrage et la gestion des workflows
 * pour des implémentations alternatives (ex. driver Temporal) sans modifier
 * le noyau du composant.
 *
 * @see ADR004 Ports et Adapters
 * @see OST001 Opportunités futures - Temporal driver
 */
interface WorkflowBackendInterface
{
    /**
     * Démarre une exécution de workflow.
     *
     * @param string   $executionId Identifiant unique de l'exécution
     * @param callable $handler     Handler du workflow (ExecutionContext, ExecutionRuntime) -> mixed
     *
     * @return mixed Le résultat du workflow
     */
    public function start(string $executionId, callable $handler): mixed;
}
