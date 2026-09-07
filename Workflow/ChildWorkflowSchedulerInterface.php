<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\ChildWorkflowOptions;

/**
 * What a {@see ChildWorkflowStub} needs: enough to start a child, and nothing more.
 *
 * Counterpart of {@see \Gplanchat\Durable\Activity\ActivitySchedulerInterface} for activities,
 * and for the same reason: the stub used to receive the whole environment, which forced keeping
 * public the form that names the child type by a string.
 *
 * The port **starts** and returns an awaitable; it does not await. That is the DUR033 rule —
 * `await()` is the only method that awaits — from which the stub had departed.
 */
interface ChildWorkflowSchedulerInterface
{
    /**
     * @param array<string, mixed> $input
     *
     * @return Awaitable<mixed>
     */
    public function startChildWorkflow(string $childWorkflowType, array $input, ?ChildWorkflowOptions $options): Awaitable;
}
