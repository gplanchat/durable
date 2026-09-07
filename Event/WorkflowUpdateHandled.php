<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\Failure\FailureEnvelope;

/**
 * Workflow update handled: arguments + result persisted for the replay
 * (simplified equivalent of a Temporal request/response pair).
 *
 * The order in the journal gives the order of application: updates share a cursor with the
 * signals, and it is their rank that orders them, not their nature.
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
         * The update failed: the caller receives the failure, the workflow carries on.
         *
         * A nullable field rather than a sibling event — the way `ActivityFailed` is one of
         * `ActivityCompleted`. The reason is in the protocol: Temporal only writes a
         * `WORKFLOW_EXECUTION_UPDATE_COMPLETED`, whose `Outcome` is either a success or a
         * failure (ADR DUR035, probe 1.3).
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
