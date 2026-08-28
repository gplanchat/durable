<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * Une durée, dite comme un exploitant la lit.
 *
 * Le seuil à partir duquel une seconde vaut mieux qu'une milliseconde est une décision, pas un
 * détail de gabarit : si chaque surface la prend pour elle, la même exécution se lit « 2.0 s » sur
 * l'une et « 2004 ms » sur l'autre, et l'exploitant qui passe de Magento à Sylius doit convertir de
 * tête. Elle se prend donc une fois, à côté du modèle d'observation dont elle décrit les faits.
 *
 * C'est de la mise en forme dans le cœur, et c'est assumé : `WorkflowRunEvent::$label` en est déjà,
 * pour la même raison — ce que plusieurs hôtes doivent dire pareil se décide en un seul endroit.
 */
final class ReadableDuration
{
    public static function of(float $seconds): string
    {
        return match (true) {
            $seconds < 1.0 => \sprintf('%d ms', (int) round($seconds * 1000.0)),
            $seconds < 90.0 => \sprintf('%.1f s', $seconds),
            default => \sprintf('%d min %02d s', (int) ($seconds / 60.0), (int) fmod($seconds, 60.0)),
        };
    }
}
