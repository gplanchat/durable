<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

/**
 * What a handler answers to an operation: now, or later.
 *
 * **The contract of the deferred form is "start this workflow", not "return a token".** Probe 3.1
 * measured it against a real server, both ways: what brings the completion back to the caller is
 * the task's `callback` attached to the workflow that fulfils the operation, through
 * `completion_callbacks` — a field that can only be set at start. Without it, the caller's history
 * stops at `NEXUS_OPERATION_STARTED` and nothing else ever arrives, whatever token is returned.
 *
 * The token is therefore not the mechanism, only the identifier. A handler that had to build it
 * itself would pick the one piece that correlates nothing, and the plumbing could no longer attach
 * the callback in time — the start has already happened.
 *
 * Once the workflow is started with that callback, the handler is no longer solicited: it is the
 * server that correlates the end of the workflow to the operation.
 */
final readonly class NexusOperationResponse
{
    private function __construct(
        public bool $isImmediate,
        public mixed $result,
        public ?string $workflowType,
        public array $workflowInput,
        public ?string $workflowId,
    ) {}

    /**
     * The handler has the answer straight away.
     *
     * A reminder from probe 1.7: this answer must leave in under ~9 s, the clock of the
     * `request-timeout`. Beyond that, the task is redelivered and the work starts over.
     */
    public static function completed(mixed $result): self
    {
        return new self(true, $result, null, [], null);
    }

    /**
     * The operation is fulfilled by a workflow, whose result will become the operation's.
     *
     * @param array<mixed> $input
     */
    public static function fulfilledByWorkflow(string $workflowType, array $input = [], ?string $workflowId = null): self
    {
        if ('' === trim($workflowType)) {
            throw new \InvalidArgumentException('A Nexus operation fulfilled by a workflow needs a workflow type.');
        }

        return new self(false, null, $workflowType, $input, $workflowId);
    }
}
