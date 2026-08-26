<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * Une page du catalogue, et de quoi demander la suivante.
 *
 * `nextCursor` est opaque : sa forme appartient au backend qui l'a émise, et le rendre au même
 * catalogue est la seule chose qu'un appelant ait le droit d'en faire. Temporal rendra son jeton de
 * page, DBAL une clé de reprise ; la vue ne fait que le transporter.
 *
 * `null` veut dire « il n'y a rien après », et pas « je ne sais pas » : une page exactement pleine
 * ne doit pas promettre une page vide, sous peine d'un « suivant » qui ne mène nulle part.
 */
final readonly class WorkflowRunPage
{
    /**
     * @param list<WorkflowRunDescription> $runs
     */
    public function __construct(
        public array $runs,
        public ?string $nextCursor = null,
    ) {}
}
