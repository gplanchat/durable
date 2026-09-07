<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

/**
 * Messenger message for resuming a suspended workflow.
 *
 * To start a new workflow, use {@see WorkflowResumeDispatcher::dispatchNewWorkflowRun}, which
 * persists the metadata and dispatches this message.
 *
 * @see \Gplanchat\Durable\Port\WorkflowResumeDispatcher
 */
final readonly class ResumeWorkflowMessage
{
    /**
     * @param list<array{name: string, arguments: array<string, mixed>}> $pendingUpdates updates to
     *        hand to the execution for this pass. They have no position in the journal yet: it is
     *        the pass that applies them that writes them there, ahead of what the workflow makes of
     *        them. Arrays and not objects, because this message is serialized.
     */
    public function __construct(
        public string $executionId,
        public array $pendingUpdates = [],
    ) {}
}
