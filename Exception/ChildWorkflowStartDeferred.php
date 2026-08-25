<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Le démarrage de l'enfant est confié au backend au lieu d'être exécuté en ligne : le parent
 * reprendra quand l'issue de l'enfant sera visible dans son historique.
 *
 * - Messenger : le handler enfant append {@see \Gplanchat\Durable\Event\ChildWorkflowCompleted}
 *   / {@see \Gplanchat\Durable\Event\ChildWorkflowFailed} sur le journal du parent ;
 * - Temporal : le serveur écrit CHILD_WORKFLOW_EXECUTION_COMPLETED / _FAILED dans l'historique.
 */
final class ChildWorkflowStartDeferred extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Child workflow start deferred to the backend.');
    }
}
