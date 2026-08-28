<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

/**
 * Les types d'erreur que nexus-rpc définit, et ce que le serveur en fait.
 *
 * Rien n'est inventé ici : la table vient du SDK **nexus-rpc**, partagé par tous les langages, et
 * l'arbitrage 1b.3 l'a reprise telle quelle. La ligne de partage est *à qui la faute* — une requête
 * malformée ou un droit manquant ne s'améliorera pas en réessayant ; une surcharge ou un délai en
 * amont, peut-être.
 *
 * Ce que ça coûte de se tromper est mesuré (sonde 1.7) : une erreur réessayable revient toutes les
 * ~9 secondes, sur l'horloge du `request-timeout`, jusqu'à épuisement du budget de l'opération. Un
 * gestionnaire qui refuse une entrée invalide en disant « réessaie » refuse la même entrée pendant
 * une minute et demie.
 */
enum NexusHandlerErrorType: string
{
    case BadRequest = 'BAD_REQUEST';
    case Unauthenticated = 'UNAUTHENTICATED';
    case Unauthorized = 'UNAUTHORIZED';
    case NotFound = 'NOT_FOUND';
    case NotImplemented = 'NOT_IMPLEMENTED';
    case Conflict = 'CONFLICT';
    case ResourceExhausted = 'RESOURCE_EXHAUSTED';
    case Internal = 'INTERNAL';
    case Unavailable = 'UNAVAILABLE';
    case UpstreamTimeout = 'UPSTREAM_TIMEOUT';
    case RequestTimeout = 'REQUEST_TIMEOUT';

    public function isRetryable(): bool
    {
        return match ($this) {
            self::BadRequest, self::Unauthenticated, self::Unauthorized,
            self::NotFound, self::NotImplemented, self::Conflict => false,
            self::ResourceExhausted, self::Internal, self::Unavailable,
            self::UpstreamTimeout, self::RequestTimeout => true,
        };
    }
}
