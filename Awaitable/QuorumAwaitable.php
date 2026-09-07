<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Settled once **a given number** of its members have succeeded (ADR DUR033).
 *
 * Generalises the common case `all()` expressed by waiting for everything: three price providers
 * out of eight are enough to decide, and waiting for the other five only pays for their latency
 * — or their outage. The quorum is the bound between the two: `all()` is the full quorum, and a
 * partial quorum says when you have enough answers.
 *
 * Unlike {@see AnyAwaitable}, it is the **succeeded** members that are counted, never the ones
 * that failed. A quorum that counted failures would be worse than useless: three outages would
 * settle the wait by throwing the first of them, when the quorum exists precisely to survive
 * members going down. The corollary fits in one line: as soon as too few members are left in the
 * race to reach the quorum, the wait is settled by the failure — without which it would never
 * settle at all.
 *
 * The result is an array **indexed by declaration position**, so that the caller knows which
 * ones answered. `all()` therefore returns the list in order, which is what the `[$a, $b] = …`
 * destructuring expects.
 *
 * @implements CompositeAwaitable<mixed>
 */
final class QuorumAwaitable implements CompositeAwaitable
{
    /** @param list<Awaitable<mixed>> $awaitables */
    public function __construct(
        private readonly array $awaitables,
        private readonly int $required,
    ) {
        // An unreachable quorum throws nothing: it suspends the execution forever, the most
        // expensive failure to diagnose in this engine.
        if ($this->required < 1 || $this->required > \count($this->awaitables)) {
            throw new \InvalidArgumentException(\sprintf(
                'A quorum of %d cannot be reached out of %d awaitables: the wait would never settle.',
                $this->required,
                \count($this->awaitables),
            ));
        }
    }

    /**
     * @return list<Awaitable<mixed>>
     */
    public function members(): array
    {
        return $this->awaitables;
    }

    public function required(): int
    {
        return $this->required;
    }

    public function isSettled(): bool
    {
        [$fulfilled, $failed] = $this->partition();

        return \count($fulfilled) >= $this->required || $this->isOutOfReach($failed);
    }

    public function getResult(): mixed
    {
        [$fulfilled, $failed, $firstFailure] = $this->partition();

        if (\count($fulfilled) >= $this->required) {
            // Exactly the quorum asked for: a member that arrived at the same time as the last
            // one awaited must not change the shape of the result.
            return \array_slice($fulfilled, 0, $this->required, true);
        }

        if (null !== $firstFailure && $this->isOutOfReach($failed)) {
            throw $firstFailure;
        }

        throw new \RuntimeException(\sprintf(
            'QuorumAwaitable: %d of %d fulfilled, %d required.',
            \count($fulfilled),
            \count($this->awaitables),
            $this->required,
        ));
    }

    /**
     * Too few members are left in the race for the quorum still to fall.
     */
    private function isOutOfReach(int $failed): bool
    {
        return $failed > \count($this->awaitables) - $this->required;
    }

    /**
     * The succeeded members, their number of failures, and the first of those failures in
     * declaration order — the one that tipped the quorum out of reach from the caller's point of
     * view.
     *
     * @return array{array<int, mixed>, int, ?\Throwable}
     */
    private function partition(): array
    {
        $fulfilled = [];
        $failed = 0;
        $firstFailure = null;
        foreach ($this->awaitables as $i => $a) {
            if (!$a->isSettled()) {
                continue;
            }

            try {
                $fulfilled[$i] = $a->getResult();
            } catch (\Throwable $e) {
                ++$failed;
                $firstFailure ??= $e;
            }
        }

        return [$fulfilled, $failed, $firstFailure];
    }
}
