<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Résultat d'un appel {@see \Gplanchat\Durable\ExecutionContext::sideEffect()} persisté dans le journal.
 *
 * Au replay, la closure du side effect n'est pas ré-exécutée ; la valeur enregistrée ici est réutilisée
 * (aligné sur Temporal {@link https://docs.temporal.io/develop/php/side-effects}).
 */
final readonly class SideEffectRecorded implements Event
{
    public function __construct(
        private string $executionId,
        private string $sideEffectId,
        private mixed $result,
    ) {
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function sideEffectId(): string
    {
        return $this->sideEffectId;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function payload(): array
    {
        return [
            'sideEffectId' => $this->sideEffectId,
            'result' => $this->result,
        ];
    }
}
