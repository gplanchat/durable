<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\ExecutionContext;

/**
 * Après résolution d'un {@see AnyAwaitable}, retire de la file les activités encore en attente
 * (best effort : si le transport ne le permet pas ou l'activité a déjà été consommée, ignorer).
 *
 * @implements Awaitable<mixed>
 */
final class CancellingAnyAwaitable implements Awaitable
{
    /** @param list<Awaitable<mixed>> $tracked */
    public function __construct(
        private readonly ExecutionContext $context,
        private readonly AnyAwaitable $inner,
        private readonly array $tracked,
    ) {
    }

    public function innerAny(): AnyAwaitable
    {
        return $this->inner;
    }

    public function then(?callable $onFulfilled = null, ?callable $onRejected = null): void
    {
        $this->inner->then($onFulfilled, $onRejected);
    }

    public function isSettled(): bool
    {
        return $this->inner->isSettled();
    }

    public function getResult(): mixed
    {
        try {
            return $this->inner->getResult();
        } finally {
            $this->cancelLosers();
        }
    }

    private function cancelLosers(): void
    {
        $winnerIndex = null;
        foreach ($this->tracked as $i => $a) {
            if ($a->isSettled()) {
                $winnerIndex = $i;
                break;
            }
        }

        if (null === $winnerIndex) {
            return;
        }

        foreach ($this->tracked as $i => $a) {
            if ($i === $winnerIndex) {
                continue;
            }
            if (!$a instanceof ActivityAwaitable) {
                continue;
            }
            if ($a->isSettled()) {
                continue;
            }
            $this->context->cancelScheduledActivity($a->activityId(), ActivityCancellationReason::RACE_SUPERSEDED);
        }
    }
}
