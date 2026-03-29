<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Comportement des workflows enfants lorsque le parent se termine (aligné Temporal Parent Close Policy).
 */
enum ParentClosePolicy: string
{
    /** Terminer l’enfant (journal enfant : échec contrôlé). */
    case Terminate = 'terminate';
    /** Ne pas intervenir sur l’enfant. */
    case Abandon = 'abandon';
    /** Demander l’annulation (événement {@see Event\WorkflowCancellationRequested} + reprise). */
    case RequestCancel = 'request_cancel';
}
