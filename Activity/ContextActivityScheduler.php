<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\ExecutionContext;

/**
 * The scheduling port, wired onto the execution context.
 *
 * Built by {@see \Gplanchat\Durable\WorkflowEnvironment::activityStub()} and never handed out:
 * that is what makes it unreachable to a workflow author. The context, for its part, does expose
 * `activity()` — but a workflow never receives the context, it receives the environment.
 *
 * @internal
 */
final class ContextActivityScheduler implements ActivitySchedulerInterface
{
    public function __construct(
        private readonly ExecutionContext $context,
    ) {}

    public function scheduleActivity(string $activityName, array $payload, ?ActivityOptions $options): Awaitable
    {
        return $this->context->activity($activityName, $payload, $options);
    }
}
