<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * La voie sur laquelle un événement se lit dans la frise.
 *
 * Le jeu est celui que le tableau de bord affiche, pas celui que les backends enregistrent : les
 * minuteurs, les workflows enfants et les effets de bord sont bel et bien journalisés, mais aucune
 * voie ne leur est consacrée aujourd'hui. Ils tombent donc sur `Other` — **listés, pas masqués** :
 * les faire disparaître de la liste ferait mentir l'ordre des événements, qui est ce qu'un
 * exploitant vient lire en premier.
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
     * équipe, autre espace de noms, autre déploiement. D'où sa voie propre plutôt que `Other` :
     * un exploitant qui voit un workflow bloqué sans voir l'opération qu'il attend cherchera la
     * panne dans son propre système, là où elle est à l'extérieur.
     */
    case Nexus = 'nexus';
    case Other = 'other';
}
