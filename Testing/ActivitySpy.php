<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use PHPUnit\Framework\Assert;

/**
 * Test double contrôlable pour les activités de workflow.
 *
 * Enregistre chaque appel (payload), retourne des valeurs préréglées ou lève
 * une exception. Compatible avec {@see \Gplanchat\Durable\RegistryActivityExecutor::register()}.
 *
 * Usage :
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
     * Retourne toujours la même valeur.
     */
    public static function returns(mixed $value): self
    {
        $spy = new self();
        $spy->returnSequence = [$value];

        return $spy;
    }

    /**
     * Lève toujours l'exception fournie.
     */
    public static function throws(\Throwable $exception): self
    {
        $spy = new self();
        $spy->exception = $exception;

        return $spy;
    }

    /**
     * Retourne les valeurs dans l'ordre à chaque appel.
     * La dernière valeur est répétée si la séquence est épuisée.
     *
     * Pour simuler des échecs dans une séquence, passer un Throwable
     * directement (il sera levé lors de l'appel correspondant) :
     * ```php
     * $spy = ActivitySpy::returnsSequence(
     *     new \RuntimeException('Temporary failure'), // tentative 1 → throw
     *     'Success after retry',                      // tentative 2 → return
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
     * Appelé par le registre d'activités avec le payload de la tâche.
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
     * @param array<string, mixed> $expectedArgs Payload attendu pour le dernier appel
     */
    public function assertCalledWith(array $expectedArgs): void
    {
        Assert::assertNotEmpty($this->calls, 'L\'activité spy n\'a pas été appelée.');
        Assert::assertEquals(
            $expectedArgs,
            $this->calls[\count($this->calls) - 1],
            'Les arguments du dernier appel ne correspondent pas.',
        );
    }

    /**
     * @param array<string, mixed> $expectedArgs Payload attendu pour le premier appel
     */
    public function assertFirstCallWith(array $expectedArgs): void
    {
        Assert::assertNotEmpty($this->calls, 'L\'activité spy n\'a pas été appelée.');
        Assert::assertEquals($expectedArgs, $this->calls[0], 'Les arguments du premier appel ne correspondent pas.');
    }

    public function assertCalledTimes(int $times): void
    {
        Assert::assertCount(
            $times,
            $this->calls,
            \sprintf('L\'activité spy devait être appelée %d fois, elle a été appelée %d fois.', $times, \count($this->calls)),
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
            \sprintf('L\'activité spy ne devait pas être appelée, mais elle a été appelée %d fois.', \count($this->calls)),
        );
    }

    /**
     * Remet à zéro les appels enregistrés et la position dans la séquence.
     * Utile pour réutiliser un spy entre plusieurs runs dans le même test.
     */
    public function reset(): void
    {
        $this->calls = [];
        $this->sequenceIndex = 0;
    }
}
