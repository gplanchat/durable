<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Réglé quand **un nombre donné** de ses membres a abouti (ADR DUR033).
 *
 * Généralise le cas courant que `all()` exprimait en attendant tout : trois fournisseurs de prix
 * sur huit suffisent à décider, et attendre les cinq autres ne fait que payer leur latence — ou
 * leur panne. Le quorum est la borne entre les deux : `all()` est le quorum plein, et un quorum
 * partiel dit à quel moment on a assez de réponses.
 *
 * Contrairement à {@see AnyAwaitable}, ce sont les membres **aboutis** qui sont comptés, jamais
 * ceux qui ont échoué. Un quorum qui compterait les échecs serait pire qu'inutile : trois pannes
 * régleraient l'attente en relevant la première d'entre elles, alors que le quorum existe
 * précisément pour survivre à des membres qui tombent. Le corollaire tient en une ligne : dès
 * qu'il reste trop peu de membres en course pour atteindre le quorum, l'attente est réglée par
 * l'échec — sans quoi elle ne se réglerait jamais.
 *
 * Le résultat est un tableau **indexé par la position de déclaration**, pour que l'appelant
 * sache lesquels ont répondu. `all()` rend donc la liste dans l'ordre, ce que la déstructuration
 * `[$a, $b] = …` attend.
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
        // Un quorum inatteignable ne relève rien : il suspend l'exécution pour toujours, la
        // panne la plus coûteuse à diagnostiquer de ce moteur.
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
            // Exactement le quorum demandé : un membre arrivé en même temps que le dernier
            // attendu ne doit pas changer la forme du résultat.
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
     * Trop peu de membres restent en course pour que le quorum tombe encore.
     */
    private function isOutOfReach(int $failed): bool
    {
        return $failed > \count($this->awaitables) - $this->required;
    }

    /**
     * Les membres aboutis, leur nombre d'échecs, et le premier de ces échecs dans l'ordre de
     * déclaration — celui qui a fait basculer le quorum hors d'atteinte du point de vue de
     * l'appelant.
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
