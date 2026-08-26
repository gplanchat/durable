<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\Failure\FailureEnvelope;

/**
 * Mise à jour workflow traitée : arguments + résultat persistés pour le replay
 * (équivalent simplifié d’un couple request/response Temporal).
 *
 * L’ordre dans le journal donne l’ordre d’application : les updates partagent un curseur avec
 * les signaux, et c’est leur rang qui les ordonne, pas leur nature.
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
        /**
         * L'update a échoué : l'appelant reçoit la défaillance, le workflow continue.
         *
         * Un champ nullable plutôt qu'un événement frère — comme `ActivityFailed` en est un de
         * `ActivityCompleted`. La raison est dans le protocole : Temporal n'écrit qu'un
         * `WORKFLOW_EXECUTION_UPDATE_COMPLETED`, dont l'`Outcome` est soit un succès soit un
         * échec (ADR DUR033, sonde 1.3).
         */
        private ?FailureEnvelope $failure = null,
    ) {}

    public function failure(): ?FailureEnvelope
    {
        return $this->failure;
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
            'failure' => null !== $this->failure ? [
                'class' => $this->failure->class,
                'message' => $this->failure->message,
                'code' => $this->failure->code,
            ] : null,
        ];
    }
}
