<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Thrown when the workflow must stop and be re-dispatched
 * (distributed mode, activity pending).
 *
 * {@see shouldDispatchResume()}: false for signals / updates — only the
 * {@see \Gplanchat\Durable\Bundle\Handler\DeliverWorkflowSignalHandler} handlers (etc.) must
 * relaunch; otherwise a **sync** Messenger transport loops forever.
 *
 * @see DUR021 Symfony Messenger integration (distributed resume)
 */
final class WorkflowSuspendedException extends \RuntimeException
{
    public function __construct(
        string $message = '',
        int $code = 0,
        ?\Throwable $previous = null,
        private readonly bool $shouldDispatchResume = true,
        private readonly bool $waitingOnTimer = false,
        /**
         * What the wait bears on, when that has a name: without it, a runner that observes an
         * execution no longer moving forward can only say "stuck", never "stuck on that
         * particular condition".
         */
        private readonly ?string $waitingOn = null,
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * If true, {@see \Gplanchat\Durable\Bundle\Handler\WorkflowRunHandler} sends a {@see \Gplanchat\Durable\Transport\WorkflowRunMessage} resume
     * (activity / timer to be moved forward by a worker).
     */
    public function shouldDispatchResume(): bool
    {
        return $this->shouldDispatchResume;
    }

    /**
     * If true, the wait bears on a durable timer: do not chain immediate resumes;
     * schedule {@see \Gplanchat\Durable\Transport\FireWorkflowTimersMessage} (possibly with a Messenger delay).
     */
    public function waitingOn(): ?string
    {
        return $this->waitingOn;
    }

    public function waitingOnTimer(): bool
    {
        return $this->waitingOnTimer;
    }
}
