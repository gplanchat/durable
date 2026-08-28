<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

/**
 * Déclare qu'une méthode de contrat est une opération Nexus.
 *
 * **Pas de suffixe `Method`, contrairement aux cinq autres attributs de méthode du dépôt.** C'est
 * l'exception assumée, et sa raison est le vocabulaire : « opération » est le mot que Nexus emploie
 * partout — dans le protocole, dans la documentation de Temporal, dans les SDK des autres langages.
 * Quelqu'un qui arrive du SDK Go cherche une opération, pas une méthode de service. Le triplet
 * `AsNexusService` / `AsNexusOperation` / `AsNexusServiceHandler` dit donc ce que Nexus désigne,
 * plutôt que la structure PHP qui le porte.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class AsNexusOperation
{
    public function __construct(
        public string $name,
    ) {}
}
