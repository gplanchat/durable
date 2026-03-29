<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Le workflow n'a pas attrapé une erreur issue d'une activité (ou une erreur catastrophique) :
 * l'intégration doit traiter cela comme un bug d'algorithme / de robustesse du workflow.
 */
final class DurableWorkflowAlgorithmFailureException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
