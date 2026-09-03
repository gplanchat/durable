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

    /**
     * La même dégradation, rendue en **structure** plutôt qu'en texte.
     *
     * {@see self::of()} sert les surfaces qui affichent un dépliant : elles veulent du texte, une
     * fois. Le profileur Symfony, lui, doit *ranger* ce qu'il a observé avant que le Profiler
     * sérialise le profil entier — une charge utile qui refuse `serialize()` n'y casse pas le
     * panneau Durable, elle casse le profil de la requête, panneaux des autres bundles compris.
     * Le besoin est le même à un type près, et la décision de dégradation doit rester ici : c'est
     * tout l'objet de cette classe.
     *
     * Trois écarts avec `of()`, chacun mesuré :
     *
     * - **`json_encode` peut lever.** Il appelle le `jsonSerialize()` de la charge utile, donc du
     *   code métier. Aucun drapeau ne couvre ce cas, et une exception qui remonte d'ici tue la
     *   requête depuis `kernel.response` — plus tôt et plus visiblement que le défaut qu'on
     *   corrigeait. D'où le `catch`.
     * - **`JSON_PRESERVE_ZERO_FRACTION`** — sans lui, un `float` de valeur entière revient en
     *   `int` et les bornes de la frise (`tMin`, `tMax`, `spanSec`), qui se déclarent `float`,
     *   mentent sur leur type.
     * - **La profondeur.** Au-delà de 512 niveaux, `json_decode` rend `null` là où l'encodage
     *   avait produit du texte. L'appelant applique donc cette méthode **clé par clé** : la
     *   charge utile pathologique disparaît seule, le reste du panneau tient.
     *
     * @return mixed la valeur ramenée aux types que JSON tient ; `null` si rien n'a survécu
     */
    public static function storable(mixed $value): mixed
    {
        try {
            $rendered = json_encode(
                $value,
                \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_PRESERVE_ZERO_FRACTION,
            );
        } catch (\Throwable) {
            return null;
        }

        if (false === $rendered) {
            return null;
        }

        return json_decode($rendered, true);
    }
}
