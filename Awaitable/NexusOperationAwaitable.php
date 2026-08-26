<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Enveloppe d'un awaitable issu de la planification d'une opération Nexus, pour permettre son
 * annulation — celle d'un perdant de {@see any()} / {@see race()} comme celle d'un workflow annulé.
 *
 * Même rôle que {@see ActivityAwaitable}, et pour la même raison : sans identité transportée,
 * {@see AwaitableCancellation} n'a rien à quoi s'adresser et l'opération continue chez le
 * fournisseur alors que plus personne n'attend son résultat. Une opération Nexus est servie par un
 * autre système, souvent une autre équipe : l'y laisser tourner coûte plus qu'une activité qu'on
 * oublie chez soi.
 *
 * L'identité portée est celle du domaine. Le pont Temporal la traduit en `scheduledEventId` réel,
 * lu dans l'historique, au moment d'émettre `RequestCancelNexusOperation` — un compteur inventé
 * localement a déjà fait taire cette commande une fois, pour les activités.
 *
 * @implements Awaitable<mixed>
 */
final class NexusOperationAwaitable implements Awaitable
{
    /**
     * @param Awaitable<mixed> $inner
     */
    public function __construct(
        private readonly Awaitable $inner,
        private readonly string $operationId,
    ) {}

    public function operationId(): string
    {
        return $this->operationId;
    }

    /**
     * @return Awaitable<mixed>
     */
    public function inner(): Awaitable
    {
        return $this->inner;
    }

    public function isSettled(): bool
    {
        return $this->inner->isSettled();
    }

    public function getResult(): mixed
    {
        return $this->inner->getResult();
    }
}
