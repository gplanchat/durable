<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

final class Deferred
{
    private bool $settled = false;
    private bool $fulfilled = false;
    private mixed $value = null;
    private ?\Throwable $reason = null;

    public function resolve(mixed $value): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->fulfilled = true;
        $this->value = $value;
    }

    public function reject(\Throwable $reason): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->fulfilled = false;
        $this->reason = $reason;
    }

    /**
     * @return Awaitable<mixed>
     */
    public function awaitable(): Awaitable
    {
        return new AwaitableAdapter($this);
    }

    /**
     * @return Awaitable<mixed>
     */
    public static function resolved(mixed $value): Awaitable
    {
        $deferred = new self();
        $deferred->resolve($value);

        return $deferred->awaitable();
    }

    public function isSettled(): bool
    {
        return $this->settled;
    }

    public function isFulfilled(): bool
    {
        return $this->fulfilled;
    }

    public function value(): mixed
    {
        return $this->value;
    }

    public function reason(): ?\Throwable
    {
        return $this->reason;
    }
}
