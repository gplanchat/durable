<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Port for waking the timers of a suspended execution.
 *
 * It exists because the core needed one single thing from Symfony Messenger, and one single thing
 * goes behind a port rather than making the component depend on a framework:
 * `messageBus->dispatch(new Envelope(new FireWorkflowTimersMessage($id), [$afterCurrentBus, $delay]))`.
 *
 * **The "after the current unit of work" is part of the contract**, not of the implementation.
 * That is what `DispatchAfterCurrentBusStamp` guarantees in Symfony: the wake-up must not be
 * delivered while the current pass has not finished writing its journal, otherwise the resume
 * re-enters itself and reads back a half-written journal. A host that publishes into a queue gets
 * it for free — another process consumes — but it must know that rather than assume it.
 *
 * @see \Gplanchat\Durable\Transport\FireWorkflowTimersMessage
 */
interface WorkflowTimerDispatcher
{
    /**
     * @param int $delayMs Wait before the wake-up. `0` means "as soon as the current work is
     *                     finished", not "right now".
     */
    public function dispatchTimerFire(string $executionId, int $delayMs = 0): void;
}
