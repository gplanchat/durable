<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Collects new workflow orchestration commands discovered during fiber replay.
 *
 * Each method corresponds to a Temporal CommandType emitted in RespondWorkflowTaskCompleted.
 * The in-memory backend appends domain events; the Temporal backend builds protobuf Command objects.
 */
interface WorkflowCommandBufferInterface
{
    /**
     * Records a new activity to schedule (COMMAND_TYPE_SCHEDULE_ACTIVITY_TASK for Temporal).
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public function scheduleActivity(string $activityId, string $activityName, array $payload, array $metadata): void;

    /**
     * Records a new timer to start (COMMAND_TYPE_START_TIMER for Temporal).
     */
    public function startTimer(string $timerId, float $scheduledAt, string $summary): void;

    /**
     * Records a side effect result (COMMAND_TYPE_RECORD_MARKER for Temporal).
     */
    public function recordSideEffect(string $sideEffectId, mixed $result): void;

    /**
     * Records a child workflow to schedule (COMMAND_TYPE_START_CHILD_WORKFLOW_EXECUTION for Temporal).
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $schedulingMetadata
     */
    public function scheduleChildWorkflow(
        string $childExecutionId,
        string $childWorkflowType,
        array $input,
        array $schedulingMetadata,
    ): void;

    /**
     * Records workflow completion (COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION for Temporal).
     */
    public function completeWorkflow(mixed $result): void;

    /**
     * Records workflow failure (COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION for Temporal).
     */
    public function failWorkflow(\Throwable $reason): void;

    /**
     * Records an activity cancellation request (COMMAND_TYPE_REQUEST_CANCEL_ACTIVITY_TASK for Temporal).
     */
    public function cancelActivity(string $activityId, string $reason): void;
}
