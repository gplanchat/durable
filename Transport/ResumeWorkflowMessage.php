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
     * @param string|null $awaitedActivityId the activity whose outcome this resume announces, sent
     *        before that outcome is journalled (DUR050); a resume that finds it missing waits
     */
    public function __construct(
        public string $executionId,
        public array $pendingUpdates = [],
        public ?string $awaitedActivityId = null,
    ) {}

    /**
     * A message serialized before DUR050 has no awaited activity; left uninitialized, reading the
     * property would be a fatal error on the worker that dequeues it.
     *
     * @param array{executionId: string, pendingUpdates?: list<array{name: string, arguments: array<string, mixed>}>, awaitedActivityId?: string|null} $data
     */
    public function __unserialize(array $data): void
    {
        $this->executionId = $data['executionId'];
        $this->pendingUpdates = $data['pendingUpdates'] ?? [];
        $this->awaitedActivityId = $data['awaitedActivityId'] ?? null;
    }
}
