<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Port pour réveiller les minuteries d'une exécution suspendue.
 *
 * Il existe parce que le cœur avait besoin d'une seule chose de Symfony Messenger, et qu'une seule
 * chose se met derrière un port plutôt que de faire dépendre le composant d'un framework :
 * `messageBus->dispatch(new Envelope(new FireWorkflowTimersMessage($id), [$afterCurrentBus, $delay]))`.
 *
 * **Le « après l'unité de travail courante » fait partie du contrat**, pas de l'implémentation.
 * C'est ce que `DispatchAfterCurrentBusStamp` garantit chez Symfony : le réveil ne doit pas être
 * délivré tant que la passe en cours n'a pas fini d'écrire son journal, sinon la reprise se
 * ré-entre elle-même et relit un journal à moitié écrit. Un hôte qui publie dans une file l'obtient
 * gratuitement — un autre processus consomme — mais il doit le savoir plutôt que le supposer.
 *
 * @see \Gplanchat\Durable\Transport\FireWorkflowTimersMessage
 */
interface WorkflowTimerDispatcher
{
    /**
     * @param int $delayMs Attente avant le réveil. `0` veut dire « dès que le travail courant est
     *                     fini », pas « tout de suite ».
     */
    public function dispatchTimerFire(string $executionId, int $delayMs = 0): void;
}
