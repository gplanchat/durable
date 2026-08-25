<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Le runner in-memory ne peut plus faire avancer l'exécution : le workflow attend quelque chose
 * que ce runner ne produit pas (signal non délivré, update, minuteur à échéance lointaine).
 *
 * Signalée plutôt que bouclée à vide, pour qu'un test qui oublie de délivrer son signal échoue
 * au lieu de geler.
 */
final class WorkflowStuckException extends \RuntimeException
{
    public function __construct(
        public readonly string $executionId,
    ) {
        parent::__construct(\sprintf(
            'Workflow %s is suspended on something the in-memory runner cannot settle '
            .'(undelivered signal / update, or a timer that is not due). '
            .'Append the awaited event to the store before running, or use the distributed backend.',
            $executionId,
        ));
    }
}
