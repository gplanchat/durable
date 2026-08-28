<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

/**
 * Déclare qu'une classe sert les opérations d'un contrat Nexus.
 *
 * Elle nomme le contrat, et rien d'autre : les noms de service et d'opération se lisent dans le
 * contrat, une seule fois. Une faute de frappe n'a donc plus deux endroits où se glisser.
 *
 * La classe **implémente** ce contrat. C'est pourquoi un contrat qui porte des opérations remplies
 * par un workflow se sépare en deux : l'interface servie, que le gestionnaire implémente, et celle
 * qui l'étend pour l'appelant. Sans cette séparation, PHP exigerait ici des corps de méthode que
 * personne ne veut écrire.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsNexusHandler
{
    /**
     * @param class-string $contract
     */
    public function __construct(
        public string $contract,
    ) {}
}
