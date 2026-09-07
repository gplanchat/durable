<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Starting the child is handed to the backend instead of being executed inline: the parent will
 * resume when the child's outcome is visible in its history.
 *
 * - Messenger: the child handler appends {@see \Gplanchat\Durable\Event\ChildWorkflowCompleted}
 *   / {@see \Gplanchat\Durable\Event\ChildWorkflowFailed} onto the parent's journal;
 * - Temporal: the server writes CHILD_WORKFLOW_EXECUTION_COMPLETED / _FAILED into the history.
 */
final class ChildWorkflowStartDeferred extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Child workflow start deferred to the backend.');
    }
}
