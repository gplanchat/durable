<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\ExecutionContext;

/**
 * Une fois le composite réglé, retire de la file les branches qui n'ont plus d'objet.
 *
 * S'applique à toute forme de course — le premier arrivé d'un {@see AnyAwaitable}, comme le
 * quorum d'un {@see QuorumAwaitable} : dans les deux cas des branches restent en vol alors que
 * le verdict est acquis, et rien ne viendra les réclamer. Best effort : si le transport ne le
 * permet pas, ou si l'activité a déjà été consommée, on n'insiste pas.
 *
 * @implements Awaitable<mixed>
 */
final class CancellingCompositeAwaitable implements Awaitable
{
    /**
     * @param CompositeAwaitable<mixed> $inner
     */
    public function __construct(
        private readonly ExecutionContext $context,
        private readonly CompositeAwaitable $inner,
    ) {}

    /**
     * @return CompositeAwaitable<mixed>
     */
    public function inner(): CompositeAwaitable
    {
        return $this->inner;
    }

    public function isSettled(): bool
    {
        return $this->inner->isSettled();
    }

    public function getResult(): mixed
    {
        // Le verdict doit être acquis avant d'annuler quoi que ce soit : sur un composite non
        // réglé, getResult() relève, et les branches encore en course sont la seule chance que
        // l'attente a de se régler.
        if (!$this->inner->isSettled()) {
            return $this->inner->getResult();
        }

        try {
            return $this->inner->getResult();
        } finally {
            AwaitableCancellation::cancelUnsettled(
                $this->context,
                $this->inner,
                ActivityCancellationReason::RACE_SUPERSEDED,
            );
        }
    }
}
