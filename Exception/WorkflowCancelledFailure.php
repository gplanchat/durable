<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Levée **dans le fiber**, au point d'attente, quand l'annulation de l'exécution a été demandée.
 *
 * Équivalent du `CanceledFailure` Temporal : le workflow peut l'attraper pour compenser, puis la
 * relancer (l'exécution se termine annulée) ou l'avaler et se terminer normalement — un workflow
 * a le droit d'ignorer une annulation.
 *
 * Livrée **une seule fois** par exécution : l'opération en attente est annulée avec la raison
 * {@see \Gplanchat\Durable\ActivityCancellationReason::WORKFLOW_CANCELLED}, ce qui sert à la fois
 * de trace de livraison et de source du rejet au replay — le workflow relève donc la même
 * exception au même endroit, sans marqueur supplémentaire côté in-memory.
 */
final class WorkflowCancelledFailure extends \RuntimeException
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $reason,
    ) {
        parent::__construct(\sprintf('Workflow %s cancellation requested: %s', $executionId, $reason));
    }
}
