<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * Reuse policy for a child workflow identifier (the Temporal equivalent, {@see IdReusePolicy}).
 */
enum WorkflowIdReusePolicy: string
{
    /** Allow a new run with the same ID. */
    case AllowDuplicate = 'allow_duplicate';
    /** Allow only if the previous execution failed or was cancelled. */
    case AllowDuplicateFailedOnly = 'allow_duplicate_failed_only';
    /** Reject if a run with that ID already exists (strict deduplication). */
    case RejectDuplicate = 'reject_duplicate';
}
