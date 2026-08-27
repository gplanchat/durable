<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Versioning;

/**
 * Les constantes d'un point de changement déclaré.
 *
 * Le nom de marqueur et les clés de `details` ne sont pas des choix : ils ont été relevés sur
 * l'historique qu'un workflow versionné du SDK Go produit, puis réémis depuis ce pont et acceptés
 * par le serveur (tâches 1.1–1.2). Les changer romprait la lisibilité d'une exécution Durable dans
 * l'UI Temporal, et l'interopérabilité avec les autres SDK.
 */
final class ChangePoint
{
    /**
     * Ce que reçoit une exécution qui a dépassé ce point **avant** qu'il ne soit déclaré.
     *
     * `-1`, comme dans les SDK officiels : le comportement d'origine n'a pas de numéro parce qu'à
     * l'époque il n'y avait rien à numéroter.
     */
    public const DEFAULT_VERSION = -1;

    /** Le nom que le serveur et l'UI Temporal reconnaissent. */
    public const MARKER_NAME = 'Version';

    /** Les deux clés de `details`, relevées sur l'historique du SDK Go. */
    public const DETAIL_CHANGE_ID = 'change-id';
    public const DETAIL_VERSION = 'version';

    /** L'attribut de recherche standard qui rend « qui est encore sur la version N » interrogeable. */
    public const SEARCH_ATTRIBUTE = 'TemporalChangeVersion';

    private function __construct() {}

    /** La valeur que le SDK Go met dans `TemporalChangeVersion` : `<change-id>-<version>`. */
    public static function searchAttributeValue(string $changeId, int $version): string
    {
        return \sprintf('%s-%d', $changeId, $version);
    }
}
