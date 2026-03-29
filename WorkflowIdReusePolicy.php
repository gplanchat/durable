<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Politique de réutilisation d’identifiant de workflow enfant (équivalent Temporal {@see IdReusePolicy}).
 */
enum WorkflowIdReusePolicy: string
{
    /** Autoriser un nouvel run avec le même ID. */
    case AllowDuplicate = 'allow_duplicate';
    /** Autoriser seulement si l’exécution précédente a échoué ou été annulée. */
    case AllowDuplicateFailedOnly = 'allow_duplicate_failed_only';
    /** Rejeter si un run avec cet ID existe déjà (déduplication stricte). */
    case RejectDuplicate = 'reject_duplicate';
}
