<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Raisons standard d'annulation d'une opération encore en attente (activité ou minuteur).
 */
final class ActivityCancellationReason
{
    /** Perdante d'un {@see \Gplanchat\Durable\WorkflowEnvironment::any()}. */
    public const RACE_SUPERSEDED = 'race_superseded';

    /**
     * Retirée parce que l'annulation de l'exécution a été demandée. Distincte de
     * {@see RACE_SUPERSEDED} : elle rejette l'attente avec
     * {@see \Gplanchat\Durable\Exception\WorkflowCancelledFailure} et fait office de trace
     * « annulation déjà livrée » pour ne pas la livrer deux fois.
     */
    public const WORKFLOW_CANCELLED = 'workflow_cancelled';

    private function __construct() {}
}
