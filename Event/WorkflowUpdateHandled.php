<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Mise à jour workflow traitée : arguments + résultat persistés pour le replay
 * (équivalent simplifié d’un couple request/response Temporal).
 *
 * L’ordre dans le journal correspond aux appels {@see \Gplanchat\Durable\ExecutionContext::waitUpdate}.
 */
final readonly class WorkflowUpdateHandled implements Event
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        private string $executionId,
        private string $updateName,
        private array $arguments,
        private mixed $result,
    ) {
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function updateName(): string
    {
        return $this->updateName;
    }

    /**
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'updateName' => $this->updateName,
            'arguments' => $this->arguments,
            'result' => $this->result,
        ];
    }
}
