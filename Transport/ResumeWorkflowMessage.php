<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Message Messenger pour reprendre un workflow suspendu.
 *
 * Pour démarrer un nouveau workflow, utiliser {@see WorkflowResumeDispatcher::dispatchNewWorkflowRun}
 * qui persiste les métadonnées et dispatch ce message.
 *
 * @see \Gplanchat\Durable\Port\WorkflowResumeDispatcher
 */
final readonly class ResumeWorkflowMessage
{
    public function __construct(
        public string $executionId,
    ) {
    }
}
