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
    public static function forBackend(string $backend): self
    {
        return new self(\sprintf(
            'The %s backend cannot serve a Nexus operation: Nexus routes a call to an endpoint served elsewhere, and this backend has no such route. Use the Temporal backend to call Nexus operations.',
            $backend,
        ));
    }
}
