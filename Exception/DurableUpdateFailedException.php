<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Le handler d'un update a relevé : l'appelant reçoit la défaillance, l'exécution poursuit.
 *
 * C'est toute la différence entre un update et un signal — un update répond, et sa réponse peut
 * être un échec sans que le workflow en soit affecté (ADR DUR033).
 */
final class DurableUpdateFailedException extends \RuntimeException
{
    public function __construct(
        private readonly string $updateName,
        string $failureMessage,
    ) {
        parent::__construct(\sprintf('Update "%s" failed: %s', $updateName, $failureMessage));
    }

    public function updateName(): string
    {
        return $this->updateName;
    }
}
