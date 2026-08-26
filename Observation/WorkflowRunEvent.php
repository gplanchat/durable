<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * Un événement de l'historique d'une exécution, dans le vocabulaire du composant.
 *
 * `label` est ce qu'un humain lit — le nom de l'activité, du signal, de la mise à jour — et non un
 * identifiant technique. Quand le backend ne sait vraiment pas nommer, l'identifiant est un
 * repli assumé, pas un défaut de conception : mieux vaut un id qu'une ligne sans nom.
 *
 * `sequence` est l'ordre d'enregistrement, pas un identifiant : il sert à ranger, et deux backends
 * n'ont aucune raison de numéroter pareil.
 */
final readonly class WorkflowRunEvent
{
    public function __construct(
        public int $sequence,
        public \DateTimeImmutable $recordedAt,
        public WorkflowRunEventKind $kind,
        public string $label,
    ) {}
}
