<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Réglé dès qu'un membre l'est, quel qu'en soit le sort — le premier arrivé décide.
 *
 * C'est le quorum de un, mais il ne se dit pas comme un {@see QuorumAwaitable} : celui-ci compte
 * les membres aboutis et rend un tableau, là où une course rend la valeur de son gagnant.
 *
 * @implements CompositeAwaitable<mixed>
 */
final class AnyAwaitable implements CompositeAwaitable
{
    /** @param list<Awaitable<mixed>> $awaitables */
    public function __construct(
        private readonly array $awaitables,
    ) {}

    /**
     * @return list<Awaitable<mixed>>
     */
    public function members(): array
    {
        return $this->awaitables;
    }

    public function isSettled(): bool
    {
        foreach ($this->awaitables as $a) {
            if ($a->isSettled()) {
                return true;
            }
        }

        return false;
    }

    public function getResult(): mixed
    {
        foreach ($this->awaitables as $a) {
            if ($a->isSettled()) {
                return $a->getResult();
            }
        }

        throw new \RuntimeException('AnyAwaitable: no awaitable settled');
    }
}
