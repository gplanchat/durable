<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Une condition sur l'état du workflow, vue comme un awaitable.
 *
 * Rien à envelopper : depuis que le contrat se réduit à `isSettled()` et `getResult()`, une
 * condition *est* un awaitable — `isSettled()` est le prédicat, littéralement. C'est ce qui
 * permet à une condition d'entrer dans le chemin d'échéance existant sans le faire bifurquer :
 * {@see \Gplanchat\Durable\WorkflowEnvironment::await()} n'appelle rien d'autre sur ses branches.
 *
 * Le prédicat est relu à chaque replay et doit donc être fonction du seul état du workflow —
 * ce qu'un replay ne reproduit pas se consigne d'abord ({@see \Gplanchat\Durable\WorkflowEnvironment::sideEffect()}).
 * Il est aussi évalué plusieurs fois par passe, et ne doit donc rien changer en le lisant.
 *
 * @implements Awaitable<null>
 */
final class ConditionAwaitable implements Awaitable
{
    /**
     * @param \Closure(): bool $predicate
     */
    public function __construct(
        private readonly \Closure $predicate,
    ) {}

    public function isSettled(): bool
    {
        return (bool) ($this->predicate)();
    }

    /**
     * Une condition ne rapporte rien : le workflow lit son propre état, qu'il n'a jamais quitté.
     */
    public function getResult(): mixed
    {
        return null;
    }

    /**
     * Où la condition est écrite — de quoi la nommer dans un diagnostic sans lui ajouter un
     * paramètre de description que tout appelant devrait alors renseigner.
     */
    public function describe(): string
    {
        $reflection = new \ReflectionFunction($this->predicate);
        $file = $reflection->getFileName();

        return \sprintf('condition at %s:%d', false !== $file ? $file : '(closure)', $reflection->getStartLine());
    }
}
