<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * A condition on the workflow state, seen as an awaitable.
 *
 * Nothing to wrap: since the contract came down to `isSettled()` and `getResult()`, a condition
 * *is* an awaitable — `isSettled()` is the predicate, literally. That is what lets a condition
 * enter the existing deadline path without making it branch:
 * {@see \Gplanchat\Durable\WorkflowEnvironment::await()} calls nothing else on its branches.
 *
 * The predicate is re-read on every replay and must therefore be a function of the workflow
 * state alone — whatever a replay does not reproduce is recorded first ({@see \Gplanchat\Durable\WorkflowEnvironment::sideEffect()}).
 * It is also evaluated several times per pass, and must therefore change nothing by reading it.
 *
 * @implements Awaitable<null>
 */
final class ConditionAwaitable implements Awaitable
{
    /**
     * @param \Closure(): bool $predicate
     */
    public function __construct(
        private readonly \Closure $predicate,
    ) {}

    public function isSettled(): bool
    {
        return (bool) ($this->predicate)();
    }

    /**
     * A condition reports nothing: the workflow reads its own state, which it never left.
     */
    public function getResult(): mixed
    {
        return null;
    }

    /**
     * Where the condition is written — enough to name it in a diagnostic without adding to it a
     * description parameter that every caller would then have to fill in.
     */
    public function describe(): string
    {
        $reflection = new \ReflectionFunction($this->predicate);
        $file = $reflection->getFileName();

        return \sprintf('condition at %s:%d', false !== $file ? $file : '(closure)', $reflection->getStartLine());
    }
}
