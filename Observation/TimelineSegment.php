<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * L'intervalle entre deux événements consécutifs d'une même action.
 *
 * Découper une action en segments n'est pas décoratif : dès que l'exécution elle-même occupe une
 * ligne, sa barre couvre tout le run, et le seul fait intéressant — les vingt-deux secondes passées
 * à attendre un worker entre deux de ses événements — disparaîtrait dans une barre qui dit « le run
 * a duré le temps du run ».
 *
 * ⚠ **Un segment qui débouche sur un démarrage n'est pas du travail, c'est une file.** D'où
 * `waiting`, hérité du `started` de l'événement qui **ferme** l'intervalle : ce qui précède la prise
 * en charge est le temps passé à attendre qu'on veuille bien commencer. Deux barres de même
 * longueur ne racontent pas la même chose, et l'exploitant devant une exécution lente cherche
 * précisément à savoir laquelle des deux il regarde — son code, ou personne au bout du fil.
 *
 * `failed` marque de même l'intervalle qui **débouche** sur un échec — le temps passé à échouer —
 * et non l'action : une activité reprise du deuxième coup porte du rouge et se termine bien.
 *
 * `title` est ce qu'un hôte affiche au survol de la barre, composé ici pour que les deux surfaces
 * le disent avec les mêmes mots. Une hachure sans légende est une devinette, et celui qui survole
 * est justement celui qui veut savoir.
 *
 * `from` et `to` sont les deux bouts, portés ici plutôt que retrouvés par rang dans la liste des
 * événements de l'action : un hôte qui compose une infobulle a besoin des deux noms, et un couplage
 * par index est exactement ce qu'on relit à trois heures du matin.
 */
final readonly class TimelineSegment
{
    public function __construct(
        public WorkflowRunEvent $from,
        public WorkflowRunEvent $to,
        public float $offset,
        public float $duration,
        public string $durationLabel,
        public bool $waiting,
        public bool $failed,
        public string $title,
    ) {}
}
