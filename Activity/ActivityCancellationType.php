<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

/**
 * Aligned with {@see \Temporal\Activity\ActivityCancellationType} (Temporal PHP SDK).
 */
enum ActivityCancellationType: int
{
    /** Requests cancellation without waiting for the activity's execution to end. */
    case TryCancel = 0;
    /** Waits for completion (success, failure, or accepted cancellation). */
    case WaitCancellationCompleted = 1;
    /** Does not wait for the worker's answer after cancellation. */
    case Abandon = 2;
}
