<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * L'issue d'une exécution, telle qu'un exploitant la lit.
 *
 * Adossée à une chaîne parce qu'elle est persistée par la projection du backend DBAL et rendue
 * telle quelle dans une URL de filtre : la valeur fait partie du contrat, pas seulement le cas.
 *
 * `ContinuedAsNew` est une fin **normale**, distincte de `Failed` : le composant traite un
 * continue-as-new comme une exécution neuve — nouvel id, nouvelles métadonnées, redispatch — et
 * l'exécution qui passe la main s'est terminée sans erreur. Les confondre ferait apparaître en
 * rouge des workflows longs parfaitement sains.
 */
enum WorkflowRunStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
    case ContinuedAsNew = 'continued_as_new';

    /**
     * Une exécution est-elle encore susceptible d'avancer ?
     */
    public function isRunning(): bool
    {
        return self::Running === $this;
    }
}
