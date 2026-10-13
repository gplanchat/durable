<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Query;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Nexus\NexusEndpoint;
use Gplanchat\Durable\Nexus\NexusOperationHeaders;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusOperationTimeouts;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Port\WorkflowCommandBufferInterface;

/**
 * The command buffer of a read-only pass: its methods drop every command. The awaitable of a
 * dropped command stays unsettled, so the workflow suspends where it would have waited, and the
 * pass appends no event, enqueues no activity and starts no timer.
 *
 * @internal
 */
final class DroppingCommandBuffer implements WorkflowCommandBufferInterface
{
    public function scheduleActivity(string $activityId, string $activityName, array $payload, ?ActivityOptions $options): void {}

    public function startTimer(string $timerId, Duration $delay, string $summary): void {}

    public function recordSideEffect(string $sideEffectId, mixed $result): void {}

    public function recordUpdateHandled(string $updateName, array $arguments, mixed $result, ?FailureEnvelope $failure, ?string $updateId = null): void {}

    public function scheduleChildWorkflow(ExecutionId $childExecutionId, string $childWorkflowType, array $input, ChildWorkflowOptions $options): void {}

    public function completeWorkflow(mixed $result): void {}

    public function completeChildWorkflow(ExecutionId $childExecutionId, mixed $result): void {}

    public function failChildWorkflow(ExecutionId $childExecutionId, \Throwable $reason): void {}

    public function recordVersion(string $changeId, int $version): void {}

    public function failWorkflow(\Throwable $reason): void {}

    public function scheduleNexusOperation(
        string $operationId,
        NexusEndpoint $endpoint,
        NexusService $service,
        NexusOperationName $operation,
        array $payload,
        NexusOperationTimeouts $timeouts,
        NexusOperationHeaders $headers,
    ): void {}

    public function cancelNexusOperation(string $operationId, string $reason): void {}

    public function cancelActivity(string $activityId, string $reason): void {}

    public function cancelTimer(string $timerId, string $reason): void {}
}
