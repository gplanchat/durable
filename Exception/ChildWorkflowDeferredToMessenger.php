<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Levée par {@see \Gplanchat\Durable\ChildWorkflowRunner} lorsque le démarrage enfant est uniquement
 * dispatché via Messenger : le parent reprendra quand le handler enfant aura append
 * {@see \Gplanchat\Durable\Event\ChildWorkflowCompleted} / {@see \Gplanchat\Durable\Event\ChildWorkflowFailed}.
 */
final class ChildWorkflowDeferredToMessenger extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Child workflow start dispatched via Messenger.');
    }
}
