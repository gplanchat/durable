<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * De quelle nature est un événement — et, par lui, l'action qu'il ouvre.
 *
 * ⚠ **Ce n'est plus une voie.** La frise rangeait par nature — « les activités », « les signaux » —
 * ce qui obligeait l'exploitant à recoller trois lignes de l'œil pour savoir combien de temps *une*
 * activité avait duré. Elle range désormais par **action** ({@see RunTimeline}), et cette
 * énumération n'y sert plus qu'à colorer : une action a la nature de l'événement qui l'ouvre.
 *
 * Le jeu est celui que les tableaux de bord distinguent, pas celui que les backends enregistrent :
 * les minuteurs, les workflows enfants et les effets de bord sont bel et bien journalisés, et
 * tombent sur `Other` — **listés, pas masqués** : les faire disparaître ferait mentir l'ordre des
 * événements, qui est ce qu'un exploitant vient lire en premier. Ils ont bien leur ligne, puisque
 * la ligne vient de l'action et non de la nature.
 *
 * `Query` n'est jamais produite par le journal : aucune requête n'y est enregistrée, elles sont
 * répondues à chaud. Seul le backend Temporal peut en produire, et c'est un fait qu'un backend a et
 * que l'autre n'a pas — pas une lacune à combler.
 */
enum WorkflowRunEventKind: string
{
    case Execution = 'execution';
    case Activity = 'activity';
    case Signal = 'signal';
    case Update = 'update';
    case Query = 'query';

    /**
     * Le seul endroit d'une exécution où l'attente est **servie par quelqu'un d'autre** — autre
     * équipe, autre espace de noms, autre déploiement. D'où sa nature propre plutôt que `Other` :
     * un exploitant qui voit un workflow bloqué sans voir l'opération qu'il attend cherchera la
     * panne dans son propre système, là où elle est à l'extérieur.
     */
    case Nexus = 'nexus';
    case Other = 'other';
}
