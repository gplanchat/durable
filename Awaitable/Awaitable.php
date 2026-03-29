<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * @template TValue
 */
interface Awaitable
{
    /**
     * @param callable(mixed): void           $onFulfilled
     * @param callable(\Throwable): void|null $onRejected
     */
    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void;

    public function isSettled(): bool;

    /**
     * @throws \Throwable When not settled or when rejected
     */
    public function getResult(): mixed;
}
