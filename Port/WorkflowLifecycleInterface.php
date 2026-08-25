<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;

/**
 * Issues du cycle de vie d'un run, telles que le backend les enregistre.
 *
 * {@see \Gplanchat\Durable\Worker\WorkflowFiberDriver} pilote le fiber — démarrage, replay,
 * suspension, terminaison — de façon identique pour tous les backends ; ce port porte les seules
 * décisions qui leur appartiennent en propre : le backend in-memory journalise des événements
 * ({@see \Gplanchat\Durable\Store\EventStoreWorkflowLifecycle}), le backend Temporal empile des
 * commandes ({@see \Gplanchat\Bridge\Temporal\Worker\TemporalWorkflowLifecycle}).
 *
 * Plusieurs méthodes ont le droit de **lever** pour interrompre le run : c'est ainsi que le
 * backend in-memory signale suspension, annulation et échec à son appelant, là où le backend
 * Temporal rend la main pour laisser partir les commandes de la tâche courante.
 */
interface WorkflowLifecycleInterface
{
    /**
     * Avant tout démarrage de fiber (ex. honorer une annulation déjà demandée).
     *
     * @throws \Throwable pour empêcher le run de démarrer
     */
    public function onBeforeRun(string $executionId): void;

    /**
     * Le handler est allé au bout.
     */
    public function onCompleted(string $executionId, mixed $result): void;

    /**
     * Le fiber attend un awaitable non réglé ; la commande correspondante est déjà dans le buffer.
     *
     * @param Awaitable<mixed> $pending
     *
     * @throws \Throwable pour signaler la suspension à l'appelant plutôt que rendre la main
     */
    public function onSuspended(string $executionId, Awaitable $pending): void;

    /**
     * Terminaison **normale** : le run courant s'arrête pour en enchaîner un nouveau.
     *
     * @throws \Throwable pour propager la demande à l'appelant
     */
    public function onContinuedAsNew(string $executionId, ContinueAsNewRequested $request): void;

    /**
     * Le handler n'a pas géré une erreur.
     *
     * @throws \Throwable pour propager l'échec à l'appelant
     */
    public function onFailed(string $executionId, \Throwable $failure): void;
}
