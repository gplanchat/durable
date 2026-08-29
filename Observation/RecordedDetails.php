<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * Ce qu'un backend a enregistré avec un événement, mis en forme pour être lu — **une fois**.
 *
 * Le contenu est le vocabulaire du backend et n'est **pas** normalisé, à dessein : décider pour
 * chaque backend lesquels de ses faits méritent un nom commun n'a de sens qu'une fois qu'on aura vu
 * ce que les exploitants y cherchent. La contrepartie assumée est qu'un journal maison peut tenir
 * une charge utile qui ne survit pas au rendu, et c'est donc à cet endroit-ci que la dégradation se
 * décide, pour toutes les surfaces à la fois.
 *
 * Elle ne se décidait pas au même endroit : le bloc Magento tolérait la sortie partielle et
 * retombait sur une ligne simple, le gabarit Sylius appelait `json_encode` sans tolérance, récoltait
 * `false` et rendait un **dépliant vide** — précisément l'écran qu'un exploitant ouvre en dernier
 * recours, et qui ne s'ouvre sur rien.
 *
 * `null` veut dire « rien à déplier ». En pratique c'est le cas où rien n'a été enregistré : la
 * sortie partielle sauve tout le reste, y compris une valeur d'un type que JSON ne tient pas — elle
 * arrive à `null` dans la charge, et l'exploitant voit à la fois ce qui a été enregistré et ce qui
 * n'a pas pu l'être. L'hôte laisse alors une ligne simple ; rendre une chaîne vide lui retirerait
 * ce choix.
 *
 * C'est de la mise en forme dans le cœur, comme {@see ReadableDuration}, et pour la même raison :
 * ce que plusieurs hôtes doivent dire pareil se décide en un seul endroit.
 */
final class RecordedDetails
{
    /**
     * @param array<string, mixed> $details
     */
    public static function of(array $details): ?string
    {
        if ([] === $details) {
            return null;
        }

        // `JSON_PARTIAL_OUTPUT_ON_ERROR` sauve le cas atteignable : une chaîne d'octets qui n'est
        // pas de l'UTF-8 valide. Sans lui, l'octet fautif emporte toute la charge utile — le reste,
        // parfaitement lisible, disparaît avec lui.
        $rendered = json_encode(
            $details,
            \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR,
        );

        // Garde défensive, et mesurée avant d'être écrite : avec la sortie partielle, aucune
        // entrée essayée ne rend `false` — ni un octet invalide, ni une ressource, ni six cents
        // niveaux d'imbrication, qui rendent tous une sortie tronquée. La signature de PHP
        // l'autorise pourtant, et une ligne sans dépliant vaut mieux qu'un écran de diagnostic qui
        // tombe sur l'événement qu'on était venu regarder.
        return false === $rendered ? null : $rendered;
    }
}
