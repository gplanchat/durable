<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * Un événement, et **où il se pose** dans la durée de son exécution.
 *
 * `offset` compte les secondes depuis le premier fait enregistré du run, pas depuis le début de
 * l'action : c'est ce qui met le repère d'une ligne sur la même verticale que celui d'une autre, et
 * donc ce qui permet de lire d'un coup d'œil qu'une activité a démarré pendant qu'une autre
 * attendait.
 *
 * Des **secondes**, jamais un pourcentage. Mettre à l'échelle demande de connaître la largeur d'une
 * colonne, et une surface qui ne rend aucun balisage n'en a pas.
 */
final readonly class TimelineEvent
{
    public function __construct(
        public WorkflowRunEvent $event,
        public float $offset,
    ) {}
}
