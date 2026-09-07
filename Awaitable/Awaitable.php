<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * A piece of work you can ask whether it is settled, and read the result from once it is.
 *
 * Two methods, and no `then()` / `otherwise()` — the interface carried one, which only its six
 * implementations called on one another. A callback has no place here for a reason that is not a
 * matter of taste: it is not journalled. On replay, the awaitable settles from the history and
 * the callback starts again; any side effect living in it would run on every re-read, which is
 * precisely what this engine exists to prevent.
 *
 * Composition therefore happens in two stages, in the order the journal will re-read them:
 * {@see \Gplanchat\Durable\WorkflowEnvironment::all()} / `any()` / `some()` assemble, and
 * {@see \Gplanchat\Durable\WorkflowEnvironment::await()} waits. The `otherwise()` is the
 * `catch` around the `await()`. See ADR DUR033.
 *
 * @template TValue
 */
interface Awaitable
{
    public function isSettled(): bool;

    /**
     * @throws \Throwable When not settled or when rejected
     */
    public function getResult(): mixed;
}
