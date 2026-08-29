<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * Une ligne de frise : **une action**, pas une nature.
 *
 * Une activité planifiée, démarrée puis terminée est une action et trois événements ; les
 * événements de l'exécution elle-même en sont une, la première. Ranger par nature — « les
 * activités », « les signaux » — obligeait l'exploitant à recoller trois lignes de l'œil pour
 * savoir combien de temps *celle-là* avait duré.
 *
 * `label` est le nom de l'événement qui **ouvre** l'action : seule la planification connaît le nom
 * de l'activité, ses suites ne portent qu'un numéro. `kind` vient de la même source — une action a
 * la nature de ce qui l'ouvre.
 *
 * `duration` peut valoir zéro sans que ce soit une anomalie : un événement qui est à lui seul son
 * action n'a aucun intervalle, et un instant n'a pas de durée.
 */
final readonly class TimelineAction
{
    /**
     * @param list<TimelineSegment> $segments
     * @param list<TimelineEvent>   $events
     */
    public function __construct(
        public WorkflowRunEventKind $kind,
        public string $label,
        public float $offset,
        public float $duration,
        public string $durationLabel,
        public array $segments,
        public array $events,
    ) {}
}
