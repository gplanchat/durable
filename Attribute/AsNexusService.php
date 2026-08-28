<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

/**
 * Déclare qu'une interface est le contrat d'un service Nexus.
 *
 * Le nom porté ici est celui que le serveur route : c'est lui, et le nom porté par chaque
 * {@see AsNexusOperation}, qui adressent une tâche entrante. Rien d'autre ne l'identifie.
 *
 * Le contrat s'écrit **une fois** et sert les deux rôles. L'appelant en dérive un stub typé ; le
 * gestionnaire implémente celui des deux contrats qui porte ce qu'il sait faire tout de suite.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsNexusService
{
    public function __construct(
        public string $name,
    ) {}
}
