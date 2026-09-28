<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Exception\SupersededPassException;

/**
 * A journal that keeps a superseded pass from writing (DUR053, #505).
 *
 * A capability beside {@see EventStoreInterface}: a store that does not implement it keeps
 * appending as it always did. Appends from outside a pass (an activity outcome, a signal) stay on
 * {@see append()} and carry no fence.
 *
 * @see DUR053
 */
interface FencedEventStoreInterface extends EventStoreInterface
{
    /**
     * Makes the execution's epoch one higher and returns it. Every fence claimed before is stale
     * from then on.
     */
    public function claimPass(string $executionId): PassFence;

    /**
     * Appends while `$fence` is still the execution's newest claim.
     *
     * @throws SupersededPassException once a newer pass has claimed the execution
     */
    public function appendFenced(Event $event, PassFence $fence): void;
}
