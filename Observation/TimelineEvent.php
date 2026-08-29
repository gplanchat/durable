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
 *
 * `title` est ce qu'un hôte affiche au survol du repère. Il est composé ici et non chez l'hôte pour
 * que les deux surfaces le disent avec les mêmes mots : un exploitant qui passe de l'une à l'autre
 * ne doit rien avoir à traduire.
 *
 * `renderedDetails` est {@see RecordedDetails::of()} appliqué une fois. `null` veut dire « rien à
 * déplier » — et c'est ce qui permet à l'hôte de laisser une ligne simple plutôt qu'un dépliant qui
 * s'ouvre sur du vide. Le fait brut reste sur `$event->details`, pour une surface qui sert des
 * données plutôt qu'une page.
 */
final readonly class TimelineEvent
{
    public function __construct(
        public WorkflowRunEvent $event,
        public float $offset,
        public string $title,
        public ?string $renderedDetails,
    ) {}
}
