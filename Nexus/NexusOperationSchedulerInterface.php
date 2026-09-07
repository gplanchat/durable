<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

use Gplanchat\Durable\Awaitable\Awaitable;

/**
 * What a {@see NexusStub} needs, and nothing more: enough to schedule an operation.
 *
 * Same assembly as {@see \Gplanchat\Durable\Activity\ActivitySchedulerInterface}, and for the same
 * reason. `nexusOperation(endpoint, service, operation, payload)` named three things with free
 * strings: the same name was written in two places, at the caller and at the handler, with nothing
 * tying them together — and a typo there produces an operation waiting for a handler whose name
 * will never match, instead of a type error. That is exactly what DUR039 removed from the surface
 * for activities, and what the Nexus side had kept.
 *
 * This port is not carried by {@see \Gplanchat\Durable\WorkflowEnvironment}: implementing it there
 * would amount to making the verb public under another name.
 */
interface NexusOperationSchedulerInterface
{
    /**
     * @param array<string, mixed> $payload
     *
     * @return Awaitable<mixed>
     */
    public function scheduleNexusOperation(
        NexusEndpoint $endpoint,
        NexusService $service,
        NexusOperationName $operation,
        array $payload,
        ?NexusOperationTimeouts $timeouts,
    ): Awaitable;
}
