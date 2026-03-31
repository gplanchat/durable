<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

final class Deferred
{
    private bool $settled = false;
    private bool $fulfilled = false;
    private mixed $value = null;
    private ?\Throwable $reason = null;

    /** @var list<array{?callable, ?callable}> */
    private array $callbacks = [];

    public function resolve(mixed $value): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->fulfilled = true;
        $this->value = $value;
        $this->notify();
    }

    public function reject(\Throwable $reason): void
    {
        if ($this->settled) {
            return;
        }
        $this->settled = true;
        $this->fulfilled = false;
        $this->reason = $reason;
        $this->notify();
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

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
    {
        $this->callbacks[] = [$onFulfilled, $onRejected];
        if ($this->settled) {
            $this->notify();
        }
    }

    private function notify(): void
    {
        foreach ($this->callbacks as $callback) {
            [$onFulfilled, $onRejected] = $callback;
            try {
                if ($this->fulfilled) {
                    if (null !== $onFulfilled) {
                        $onFulfilled($this->value);
                    }
                } else {
                    if (null !== $onRejected && null !== $this->reason) {
                        $onRejected($this->reason);
                    }
                }
            } catch (\Throwable $e) {
                // ADR018: journal minimal — un callback then() ne doit pas faire échouer les autres ; la cause reste traçable.
                error_log(\sprintf(
                    '[Gplanchat\Durable\Awaitable\Deferred] callback in notify() threw %s: %s at %s:%d',
                    $e::class,
                    $e->getMessage(),
                    $e->getFile(),
                    $e->getLine(),
                ));
            }
        }
        $this->callbacks = [];
    }
}
