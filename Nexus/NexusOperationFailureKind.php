<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

/**
 * Pourquoi une opération Nexus n'a pas abouti.
 *
 * Les quatre natures ne sont pas un confort de lecture : elles appellent des gestes différents.
 * Un appelant compense sur {@see self::OperationFailed} — le handler a tourné et a dit non. Il
 * peut réessayer sur {@see self::HandlerError}, où le handler n'a pas tourné du tout et où le
 * serveur dit lui-même si la reprise a un sens. Il ne fait ni l'un ni l'autre sur
 * {@see self::Cancellation}, qu'il a le plus souvent demandée. Et {@see self::Timeout} dit que la
 * borne a parlé avant l'opération, ce qui n'est un échec de personne.
 *
 * Aplatir les quatre sur un échec générique effacerait justement ce qui permet de choisir.
 */
enum NexusOperationFailureKind: string
{
    /** Le handler a tourné et a rendu un échec. */
    case OperationFailed = 'operation_failed';

    /** Le handler n'a pas pu tourner ; le serveur porte le comportement de reprise. */
    case HandlerError = 'handler_error';

    /** Une borne s'est écoulée avant que l'opération n'aboutisse. */
    case Timeout = 'timeout';

    /** L'opération a été annulée. */
    case Cancellation = 'cancellation';
}
