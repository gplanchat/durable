<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

/**
 * Aligné sur {@see \Temporal\Activity\ActivityCancellationType} (SDK Temporal PHP).
 */
enum ActivityCancellationType: int
{
    /** Demande d’annulation sans attendre la fin d’exécution de l’activité. */
    case TryCancel = 0;
    /** Attendre la complétion (succès, échec ou annulation acceptée). */
    case WaitCancellationCompleted = 1;
    /** Ne pas attendre la réponse du worker après annulation. */
    case Abandon = 2;
}
