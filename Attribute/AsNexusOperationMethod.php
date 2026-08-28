<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

/**
 * Déclare qu'une méthode de contrat est une opération Nexus.
 *
 * Préfixe `As` comme partout ailleurs depuis v0.1.0-alpha8 : le dépôt n'a plus qu'une convention
 * de nommage pour ses attributs, classe et méthode confondues.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class AsNexusOperationMethod
{
    public function __construct(
        public string $name,
    ) {}
}
