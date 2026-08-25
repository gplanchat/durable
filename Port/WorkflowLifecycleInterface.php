<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;

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
     * Avant tout démarrage de fiber.
     *
     * @throws \Throwable pour empêcher le run de démarrer
     */
    public function onBeforeRun(string $executionId): void;

    /**
     * Une annulation a-t-elle été demandée et **pas encore livrée** au workflow ?
     *
     * La livraison doit être unique par exécution : le fiber étant rejoué depuis le début à
     * chaque tâche, un « oui » permanent ferait relever l'annulation dans les attentes de
     * compensation elles-mêmes, et le workflow ne pourrait jamais compenser.
     */
    public function isCancellationPending(string $executionId): bool;

    /**
     * L'annulation a traversé le handler sans être avalée : l'exécution se termine annulée.
     *
     * @throws \Throwable pour propager la fin à l'appelant
     */
    /**
     * L'annulation vient d'être relevée dans le fiber ; `$cancelledOperationIds` liste les
     * opérations retirées à cette occasion.
     *
     * Le backend doit pouvoir, au rejeu, rejeter **ces mêmes** opérations avec la même
     * exception : sans quoi le `catch` du workflow ne matcherait plus et la compensation
     * divergerait d'une tâche à l'autre.
     *
     * @param list<string> $cancelledOperationIds
     */
    public function onCancellationDelivered(string $executionId, array $cancelledOperationIds): void;

    public function onCancelled(string $executionId, WorkflowCancelledFailure $failure): void;

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
