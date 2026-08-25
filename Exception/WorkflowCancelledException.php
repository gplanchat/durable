<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * L'exécution s'est arrêtée sur une annulation demandée : terminaison **normale**, pas un échec.
 *
 * Propagée par {@see \Gplanchat\Durable\ExecutionEngine} pour que l'appelant cesse de redélivrer
 * la reprise (cf. {@see \Gplanchat\Durable\Bundle\Handler\ResumeWorkflowHandler}).
 */
final class WorkflowCancelledException extends \RuntimeException
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $reason,
    ) {
        parent::__construct(\sprintf('Workflow %s cancelled: %s', $executionId, $reason));
    }
}
