<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

/**
 * Déclare qu'un workflow remplit une opération Nexus : son résultat devient celui de l'opération.
 *
 * La déclaration vit **sur le workflow**, et non sur le contrat, pour deux raisons.
 *
 * Le contrat est lu par l'appelant, qui n'a pas à connaître la classe qui le sert — l'y nommer
 * ferait fuir l'implémentation à travers la frontière que Nexus existe pour poser.
 *
 * Et c'est ici que le code vit. Une opération remplie par un workflow n'a pas de corps de
 * gestionnaire : la plomberie démarre le workflow avec le callback de la tâche attaché, et le
 * serveur livre son résultat à l'appelant. Le déclarer ailleurs obligerait à écrire une méthode
 * vide pour dire qu'il n'y a rien à écrire.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class FulfilsNexusOperation
{
    /**
     * @param class-string $contract
     */
    public function __construct(
        public string $contract,
        public string $operation,
    ) {}
}
