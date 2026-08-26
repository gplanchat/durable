<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Un travail dont on peut demander s'il est réglé, et lire le résultat quand il l'est.
 *
 * Deux méthodes, et pas de `then()` / `otherwise()` — l'interface en a porté un, que seules ses
 * six implémentations s'appelaient entre elles. Un callback n'a pas sa place ici pour une raison
 * qui n'est pas de goût : il n'est pas journalisé. Au replay, l'awaitable se règle depuis
 * l'historique et le callback repart ; tout effet de bord qui y vivrait s'exécuterait à chaque
 * relecture, ce que ce moteur existe précisément pour empêcher.
 *
 * La composition se fait donc en deux temps, dans l'ordre où le journal les relira :
 * {@see \Gplanchat\Durable\WorkflowEnvironment::all()} / `any()` / `some()` assemblent, et
 * {@see \Gplanchat\Durable\WorkflowEnvironment::await()} attend. Le `otherwise()`, c'est le
 * `catch` autour de l'`await()`. Voir ADR DUR033.
 *
 * @template TValue
 */
interface Awaitable
{
    public function isSettled(): bool;

    /**
     * @throws \Throwable When not settled or when rejected
     */
    public function getResult(): mixed;
}
