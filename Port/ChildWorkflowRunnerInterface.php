<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Port: ability to start a child workflow (inline or deferred).
 *
 * Concrete implementations:
 * - {@see \Gplanchat\Durable\ChildWorkflowRunner} – in-memory / async-messenger runner
 * - {@see \Gplanchat\Bridge\Temporal\Worker\TemporalChildWorkflowRunner} – Temporal server-driven
 */
interface ChildWorkflowRunnerInterface
{
    /**
     * Returns true when starting a child is handed to the backend instead of running inline.
     */
    public function defersChildStart(): bool;

    /**
     * Run (or defer) a child workflow and return its result.
     *
     * @param array<string, mixed> $input
     *
     * @throws \Gplanchat\Durable\Exception\ChildWorkflowStartDeferred when deferred
     */
    public function runChild(string $childExecutionId, string $workflowType, array $input, ?string $parentExecutionId = null): mixed;
}
