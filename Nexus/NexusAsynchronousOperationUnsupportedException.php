<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

/**
 * Le handler a répondu qu'il répondrait **plus tard**, et cet incrément ne sait pas l'entendre.
 *
 * Une opération Nexus asynchrone démarre en rendant un jeton — `NEXUS_OPERATION_STARTED` le porte
 * — puis se termine par un rappel, à un moment quelconque. C'est ce jeton qui la distingue d'une
 * activité, et c'est tout le protocole de complétion asynchrone qui manque ici : rien, côté
 * appelant, ne sait recevoir ce rappel.
 *
 * D'où ce refus plutôt qu'une attente. Laisser l'opération en vol suspendrait le workflow sur un
 * résultat que personne ne viendra livrer, sans rien dans les logs — la panne muette que ce
 * composant refuse partout ailleurs. L'aveu immédiat nomme l'opération et la limite.
 */
final class NexusAsynchronousOperationUnsupportedException extends \RuntimeException
{
    public static function forOperation(string $operationId): self
    {
        return new self(\sprintf(
            'Nexus operation "%s" started asynchronously: the handler returned a token and will '
            . 'complete by callback later. This increment only supports synchronous operations, and '
            . 'nothing here can receive that callback — the wait would never end.',
            $operationId,
        ));
    }
}
