<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

/**
 * Le backend d'exécution en usage ne sait pas servir une opération Nexus.
 *
 * Nexus fait appeler par un workflow une opération que **quelqu'un d'autre** sert — autre
 * namespace, autre équipe, autre déploiement. Un backend qui tient son journal en local n'a rien
 * à quoi router cet appel, et aucun repli honnête : il ne peut ni l'exécuter, ni l'ignorer sans
 * laisser le workflow attendre un résultat que personne ne produira.
 *
 * D'où ce refus, immédiat et nommé, plutôt qu'une commande acceptée puis perdue.
 */
final class NexusUnsupportedByBackendException extends \RuntimeException
{
    /**
     * Le refus **à l'enregistrement**, et il ne dit pas la même chose que celui de l'appel.
     *
     * Un appel sur un backend sans route échoue à l'appel : la faute devient visible au moment où
     * elle est commise. Servir est l'inverse — un gestionnaire déclaré là n'est pas un appel qui
     * échoue, c'est un service qui **ne reçoit jamais rien**, sans une ligne de log. Il n'y a
     * aucune requête à faire échouer plus tard, donc le refus a lieu ici ou nulle part.
     */
    public static function forHandlerOn(string $backend): self
    {
        return new self(\sprintf(
            'A Nexus handler cannot be served by the %s backend: it has no route, so a handler registered here never receives anything — no error, no log line, a task queue nobody polls. Use the Temporal backend to serve Nexus operations.',
            $backend,
        ));
    }

    public static function forBackend(string $backend): self
    {
        return new self(\sprintf(
            'The %s backend cannot serve a Nexus operation: Nexus routes a call to an endpoint served elsewhere, and this backend has no such route. Use the Temporal backend to call Nexus operations.',
            $backend,
        ));
    }
}
