<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Message Messenger : remettre un update à l'exécution, qui le traitera.
 *
 * Aucun résultat ici : l'issue d'un update est le retour de son handler, et seule une passe du
 * workflow la produit — {@see \Gplanchat\Durable\Bundle\Handler\DeliverWorkflowUpdateHandler}.
 */
final readonly class DeliverWorkflowUpdateMessage
{
    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public string $executionId,
        public string $updateName,
        public array $arguments = [],
    ) {}
}
