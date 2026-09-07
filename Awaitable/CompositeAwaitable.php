<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * An awaitable that assembles others.
 *
 * Two places in the engine need to walk an assembly rather than look at it from the outside:
 * {@see AwaitableInspector::waitsOnTimer()}, which decides whether a wake-up must be scheduled,
 * and {@see AwaitableCancellation}, which has to reach the leaves to take them off the queue.
 * Both did it through a chain of `instanceof` over the known composites; adding one was enough
 * for its members to stop being seen, silently — an execution with no wake-up, or an orphaned
 * activity. See ADR DUR033.
 *
 * @template TValue
 *
 * @extends Awaitable<TValue>
 */
interface CompositeAwaitable extends Awaitable
{
    /**
     * @return list<Awaitable<mixed>>
     */
    public function members(): array;
}
