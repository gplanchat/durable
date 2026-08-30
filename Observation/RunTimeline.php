<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * L'historique d'une exécution, projeté en frise — **une fois, pour toutes les surfaces**.
 *
 * Deux tableaux de bord la dérivaient chacun du sien : Magento plaçait les actions dans le temps et
 * distinguait la file du travail, Sylius empilait des blocs sans position. Le même run, enregistré
 * par le même backend, se lisait donc différemment selon l'application ouverte — et les deux
 * surfaces qui restent à écrire n'avaient d'autre source de vérité que celle que leur auteur
 * ouvrirait en premier.
 *
 * ⚠ **Cette projection mesure, elle ne dessine pas.** Tout est en secondes : `span`, `offset`,
 * `duration`. Le bloc Magento dont elle est issue rendait des flottants de 0 à 100, c'est-à-dire des
 * largeurs CSS — le cœur se serait mis à dessiner pour une surface qui ne rend aucun balisage. Mettre
 * à l'échelle est le métier de l'hôte, et c'est aussi chez lui que vit la règle qui va avec : une
 * attente de quatre millisecondes ne doit pas dessiner plus large que six millisecondes de travail.
 *
 * L'échelle va du premier au dernier fait **enregistré**, pas du début à la fin de l'exécution : une
 * exécution en cours n'a pas de fin, et une frise qui s'arrête au dernier fait connu ne prétend rien
 * savoir de plus.
 *
 * @see DUR037 l'observation d'un run est une projection
 * @see DUR049 une projection, plusieurs habillages : la présentation se décide à côté du modèle
 */
final readonly class RunTimeline
{
    /**
     * @param list<TimelineAction> $actions
     */
    private function __construct(
        public float $span,
        public string $spanLabel,
        public array $actions,
    ) {}

    /**
     * @param list<WorkflowRunEvent> $history
     */
    public static function of(array $history): self
    {
        if ([] === $history) {
            // Une exécution purgée, ou jamais vue, n'est pas une erreur d'appel : l'hôte affiche une
            // frise vide sans avoir à distinguer « rien » de `null`.
            return new self(0.0, ReadableDuration::of(0.0), []);
        }

        $moments = [];
        $grouped = [];
        foreach ($history as $event) {
            $moments[$event->sequence] = (float) $event->recordedAt->format('U.u');
            // Un événement sans action est à lui seul la sienne : sa séquence suffit à le
            // distinguer, et il occupe sa ligne comme n'importe quelle autre action.
            $grouped[$event->actionKey ?? ('#' . $event->sequence)][] = $event;
        }

        $first = min($moments);
        $span = max($moments) - $first;

        $actions = [];
        foreach ($grouped as $group) {
            $opening = $group[0];
            $closing = $group[\count($group) - 1];
            $from = $moments[$opening->sequence] - $first;
            $to = $moments[$closing->sequence] - $first;

            $actions[] = new TimelineAction(
                $opening->kind,
                // Le nom de l'action est celui de l'événement qui l'ouvre : c'est la planification
                // qui connaît le nom de l'activité, ses suites ne portent qu'un numéro.
                $opening->label,
                $from,
                $to - $from,
                ReadableDuration::of($to - $from),
                self::segments($group, $moments, $first),
                array_map(
                    static fn(WorkflowRunEvent $event): TimelineEvent => new TimelineEvent(
                        $event,
                        $moments[$event->sequence] - $first,
                        \sprintf(
                            '#%d · %s · %s',
                            $event->sequence,
                            $event->recordedAt->format('H:i:s.v'),
                            $event->label,
                        ),
                        RecordedDetails::of($event->details),
                        $opening->label,
                    ),
                    $group,
                ),
            );
        }

        return new self($span, ReadableDuration::of($span), $actions);
    }

    /**
     * Les mêmes événements, déroulés dans l'ordre où ils ont été enregistrés.
     *
     * La frise groupe pour répondre « combien de temps » ; un journal déroule pour répondre « dans
     * quel ordre », et c'est ce qu'un exploitant lit en premier. Rendre le second dans l'ordre du
     * premier ferait mentir l'ordre. Chaque ligne garde le nom de son action, ce qui permet de
     * retrouver dans l'un ce qu'on a repéré dans l'autre.
     *
     * @return list<TimelineEvent>
     */
    public function journal(): array
    {
        $rows = array_merge(...array_map(
            static fn(TimelineAction $action): array => $action->events,
            $this->actions,
        ));

        usort($rows, static fn(TimelineEvent $left, TimelineEvent $right): int => $left->event->sequence <=> $right->event->sequence);

        return $rows;
    }

    /**
     * Un segment par intervalle entre deux événements consécutifs de l'action.
     *
     * Une action d'un seul événement n'a aucun intervalle, donc aucun segment : un repère seul dit
     * déjà tout ce qu'il y a à dire d'un instant.
     *
     * @param list<WorkflowRunEvent> $group
     * @param array<int, float>      $moments
     *
     * @return list<TimelineSegment>
     */
    private static function segments(array $group, array $moments, float $first): array
    {
        $segments = [];
        for ($index = 1, $count = \count($group); $index < $count; ++$index) {
            $opening = $group[$index - 1];
            $closing = $group[$index];
            $from = $moments[$opening->sequence] - $first;
            $to = $moments[$closing->sequence] - $first;

            $segments[] = new TimelineSegment(
                $opening,
                $closing,
                $from,
                $to - $from,
                ReadableDuration::of($to - $from),
                // Ce qui précède une prise en charge est une attente, pas du travail.
                $closing->started,
                // Rouge sur l'intervalle qui **débouche** sur l'échec, pas sur l'action entière.
                $closing->failed,
                // La nature de l'intervalle est nommée : une hachure sans légende est une
                // devinette, et celui qui survole la barre est celui qui veut savoir.
                \sprintf(
                    '%s%s · #%d → #%d · %s → %s',
                    $closing->started ? 'waiting to be picked up · ' : '',
                    ReadableDuration::of($to - $from),
                    $opening->sequence,
                    $closing->sequence,
                    $opening->label,
                    $closing->label,
                ),
            );
        }

        return $segments;
    }
}
