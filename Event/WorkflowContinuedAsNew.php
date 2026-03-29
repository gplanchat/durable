<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Le run courant se termine pour enchaîner un nouveau run avec payload / type donnés.
 *
 * L’historique du **nouveau** `executionId` doit être vide ; le dispatch est à la charge du
 * {@see \Gplanchat\Durable\Bundle\Handler\WorkflowRunHandler} ou de l’appelant.
 */
final readonly class WorkflowContinuedAsNew implements Event
{
    /**
     * @param array<string, mixed> $nextPayload
     * @param array<string, mixed> $continuationMetadata Équivalent {@see \Temporal\Workflow\ContinueAsNewOptions} sérialisé (task_queue, timeouts, …)
     */
    public function __construct(
        private string $executionId,
        private string $nextWorkflowType,
        private array $nextPayload,
        private array $continuationMetadata = [],
    ) {
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function nextWorkflowType(): string
    {
        return $this->nextWorkflowType;
    }

    /**
     * @return array<string, mixed>
     */
    public function nextPayload(): array
    {
        return $this->nextPayload;
    }

    /**
     * @return array<string, mixed>
     */
    public function continuationMetadata(): array
    {
        return $this->continuationMetadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $p = [
            'nextWorkflowType' => $this->nextWorkflowType,
            'nextPayload' => $this->nextPayload,
        ];
        if ([] !== $this->continuationMetadata) {
            $p['continuationMetadata'] = $this->continuationMetadata;
        }

        return $p;
    }
}
