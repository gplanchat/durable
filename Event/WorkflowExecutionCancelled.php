<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Fin d'exécution sur annulation — contrepartie terminale de {@see WorkflowCancellationRequested},
 * qui n'en avait aucune : un enfant en {@see \Gplanchat\Durable\ParentClosePolicy::RequestCancel}
 * restait « actif » pour toujours aux yeux de
 * {@see \Gplanchat\Durable\ParentChildWorkflowCoordinator::isChildRunActive()}.
 *
 * ponytail: annulation coopérative, honorée au point de reprise suivant. Aucune exception n'est
 * injectée dans le fiber en cours ; un vrai `CancelledFailure` façon Temporal demanderait de
 * reprendre le fiber par l'exception, pas de le rejouer.
 */
final readonly class WorkflowExecutionCancelled implements Event
{
    public function __construct(
        private string $executionId,
        private string $reason,
        private ?string $sourceParentExecutionId = null,
    ) {
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function sourceParentExecutionId(): ?string
    {
        return $this->sourceParentExecutionId;
    }

    public function payload(): array
    {
        return [
            'reason' => $this->reason,
            'sourceParentExecutionId' => $this->sourceParentExecutionId,
        ];
    }
}
