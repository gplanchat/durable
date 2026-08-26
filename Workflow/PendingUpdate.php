<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Failure\FailureEnvelope;

/**
 * Un update qui n'est pas encore dans le journal, remis à l'exécution pour la passe en cours.
 *
 * La sonde de la tâche 1.3 l'a montré : un update entrant n'atteint pas le worker par
 * l'historique. Il arrive à côté, sur la tâche, et le worker l'accepte *et* y répond sur cette
 * même tâche. C'est donc pour la première passe seulement — l'acceptation écrit la requête dans
 * l'historique, et dès le replay suivant l'update est positionné comme n'importe quel signal.
 *
 * L'issue est déposée ici plutôt que journalisée par l'exécution : c'est l'appelant de la passe
 * qui répond, comme le worker renvoie sa `Response` au serveur, et lui qui consigne.
 */
final class PendingUpdate
{
    public bool $handled = false;

    public mixed $result = null;

    public ?FailureEnvelope $failure = null;

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments = [],
    ) {}
}
