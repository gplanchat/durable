<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Why a caller's update failed: its handler threw, or no pass applied it.
 */
enum UpdateFailureReason
{
    case HandlerFailed;

    /**
     * No update outcome was journalled for it: the workflow declares no update of that name, or was
     * not waiting on a condition when the update arrived (a pass applies updates only there).
     */
    case NotHandled;
}
