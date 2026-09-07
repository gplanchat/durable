<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use PHPUnit\Framework\Assert;

/**
 * A controllable test double for workflow activities.
 *
 * It records every call (payload), returns preset values or throws an
 * exception. Compatible with {@see \Gplanchat\Durable\RegistryActivityExecutor::register()}.
 *
 * Usage:
 * ```php
 * $spy = ActivitySpy::returns('hello');
 * $env->register('greet', $spy);
 * // ... run workflow
 * $spy->assertCalledTimes(1);
 * $spy->assertCalledWith(['name' => 'World']);
 * ```
 */
final class ActivitySpy
{
    /** @var list<array<string, mixed>> */
    private array $calls = [];

    /** @var list<mixed> */
    private array $returnSequence = [];

    private int $sequenceIndex = 0;

    private ?\Throwable $exception = null;

    private function __construct() {}

    /**
     * Always returns the same value.
     */
    public static function returns(mixed $value): self
    {
        $spy = new self();
        $spy->returnSequence = [$value];

        return $spy;
    }

    /**
     * Always throws the exception it was given.
     */
    public static function throws(\Throwable $exception): self
    {
        $spy = new self();
        $spy->exception = $exception;

        return $spy;
    }

    /**
     * Returns the values in order, one per call.
     * The last value is repeated once the sequence is exhausted.
     *
     * To simulate failures inside a sequence, pass a Throwable
     * directly (it is thrown on the matching call):
     * ```php
     * $spy = ActivitySpy::returnsSequence(
     *     new \RuntimeException('Temporary failure'), // attempt 1 → throw
     *     'Success after retry',                      // attempt 2 → return
     * );
     * ```
     */
    public static function returnsSequence(mixed ...$values): self
    {
        $spy = new self();
        $spy->returnSequence = array_values($values);

        return $spy;
    }

    /**
     * Called by the activity registry with the task payload.
     *
     * @param array<string, mixed> $payload
     */
    public function __invoke(array $payload): mixed
    {
        $this->calls[] = $payload;

        if (null !== $this->exception) {
            throw $this->exception;
        }

        if ([] === $this->returnSequence) {
            return null;
        }

        if ($this->sequenceIndex >= \count($this->returnSequence)) {
            $last = end($this->returnSequence);
            if ($last instanceof \Throwable) {
                throw $last;
            }

            return $last;
        }

        $value = $this->returnSequence[$this->sequenceIndex++];
        if ($value instanceof \Throwable) {
            throw $value;
        }

        return $value;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function calls(): array
    {
        return $this->calls;
    }

    public function callCount(): int
    {
        return \count($this->calls);
    }

    /**
     * @param array<string, mixed> $expectedArgs Payload expected for the last call
     */
    public function assertCalledWith(array $expectedArgs): void
    {
        Assert::assertNotEmpty($this->calls, 'The activity spy was never called.');
        Assert::assertEquals(
            $expectedArgs,
            $this->calls[\count($this->calls) - 1],
            'The arguments of the last call do not match.',
        );
    }

    /**
     * @param array<string, mixed> $expectedArgs Payload expected for the first call
     */
    public function assertFirstCallWith(array $expectedArgs): void
    {
        Assert::assertNotEmpty($this->calls, 'The activity spy was never called.');
        Assert::assertEquals($expectedArgs, $this->calls[0], 'The arguments of the first call do not match.');
    }

    public function assertCalledTimes(int $times): void
    {
        Assert::assertCount(
            $times,
            $this->calls,
            \sprintf('The activity spy was expected to be called %d times, it was called %d times.', $times, \count($this->calls)),
        );
    }

    public function assertCalledOnce(): void
    {
        $this->assertCalledTimes(1);
    }

    public function assertNotCalled(): void
    {
        Assert::assertEmpty(
            $this->calls,
            \sprintf('The activity spy was not expected to be called, but it was called %d times.', \count($this->calls)),
        );
    }

    /**
     * Resets the recorded calls and the position in the sequence.
     * Useful to reuse a spy across several runs in the same test.
     */
    public function reset(): void
    {
        $this->calls = [];
        $this->sequenceIndex = 0;
    }
}
