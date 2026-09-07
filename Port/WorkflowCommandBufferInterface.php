<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Failure\FailureEnvelope;

/**
 * Collects new workflow orchestration commands discovered during fiber replay.
 *
 * Each method corresponds to a Temporal CommandType emitted in RespondWorkflowTaskCompleted.
 * The in-memory backend appends domain events; the Temporal backend builds protobuf Command objects.
 *
 * **This port carries value objects, not primitives.** An implementation receives the options the
 * caller constructed — with their invariants intact — and owns the translation to its own
 * representation, including any serialisation to a wire format and any reading of a clock. See
 * ADR DUR031.
 *
 * Third-party implementations written against the previous signatures must update three methods:
 *
 * | Before | Now |
 * |---|---|
 * | `scheduleActivity(..., array $metadata)` | `scheduleActivity(..., ?ActivityOptions $options)` |
 * | `scheduleChildWorkflow(..., array $schedulingMetadata)` | `scheduleChildWorkflow(..., ChildWorkflowOptions $options)` |
 * | `startTimer($id, float $scheduledAt, ...)` | `startTimer($id, Duration $delay, ...)` |
 *
 * `startTimer` is the one that changes meaning, not just type: it now receives the **delay** to
 * wait. Turning it into a deadline is the implementation's decision, and the implementation's
 * clock.
 */
interface WorkflowCommandBufferInterface
{
    /**
     * Records a new activity to schedule (COMMAND_TYPE_SCHEDULE_ACTIVITY_TASK for Temporal).
     *
     * Receives the options exactly as the caller built them: their invariants carry through, and
     * it is up to the backend to translate them into its own primitives and to timestamp the
     * enqueuing with its own clock.
     *
     * @param array<string, mixed> $payload
     */
    public function scheduleActivity(string $activityId, string $activityName, array $payload, ?ActivityOptions $options): void;

    /**
     * Records a new timer to start (COMMAND_TYPE_START_TIMER for Temporal).
     *
     * Receives the **delay**, not a deadline: the in-memory backend needs an instant to compare
     * against its clock, the Temporal server demands a duration. Each does its own arithmetic,
     * the core reads no clock.
     */
    public function startTimer(string $timerId, Duration $delay, string $summary): void;

    /**
     * Records a side effect result (COMMAND_TYPE_RECORD_MARKER for Temporal).
     */
    public function recordSideEffect(string $sideEffectId, mixed $result): void;

    /**
     * Records the outcome of an update the execution has just handled.
     *
     * Called at the moment the update is applied, so its record lands **before** whatever the
     * workflow does in response — the same order Temporal produces, where the acceptance command
     * precedes the workflow's commands and the server writes the events.
     *
     * On the Temporal backend this is deliberately a no-op: there, the **server** writes
     * `WORKFLOW_EXECUTION_UPDATE_ACCEPTED` and `..._UPDATE_COMPLETED` from the protocol messages
     * the worker sends back, and a worker that also journalled them would double-record.
     *
     * @param array<string, mixed> $arguments
     */
    public function recordUpdateHandled(string $updateName, array $arguments, mixed $result, ?FailureEnvelope $failure): void;

    /**
     * Records a child workflow to schedule (COMMAND_TYPE_START_CHILD_WORKFLOW_EXECUTION for Temporal).
     *
     * @param array<string, mixed> $input
     */
    public function scheduleChildWorkflow(
        string $childExecutionId,
        string $childWorkflowType,
        array $input,
        ChildWorkflowOptions $options,
    ): void;

    /**
     * Records workflow completion (COMMAND_TYPE_COMPLETE_WORKFLOW_EXECUTION for Temporal).
     */
    public function completeWorkflow(mixed $result): void;

    /**
     * Records the outcome of a child workflow executed **inline** (in-memory backend with no
     * Messenger deferred start), in the parent's journal.
     *
     * With no Temporal equivalent: there the server writes CHILD_WORKFLOW_EXECUTION_COMPLETED.
     */
    public function completeChildWorkflow(string $childExecutionId, mixed $result): void;

    /**
     * The failure counterpart of {@see completeChildWorkflow()}.
     */
    public function failChildWorkflow(string $childExecutionId, \Throwable $reason): void;

    /**
     * Records workflow failure (COMMAND_TYPE_FAIL_WORKFLOW_EXECUTION for Temporal).
     */
    /**
     * Records the version an execution resolved for a declared change point.
     *
     * Written once, the first time the execution reaches the point. Everything after that reads it
     * back rather than deciding again.
     */
    public function recordVersion(string $changeId, int $version): void;

    public function failWorkflow(\Throwable $reason): void;

    /**
     * Records an activity cancellation request (COMMAND_TYPE_REQUEST_CANCEL_ACTIVITY_TASK for Temporal).
     */
    /**
     * Schedules a Nexus operation: a call served by an outside endpoint.
     *
     * @param array<string, mixed> $payload
     *
     * @throws \Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException if the backend cannot route the call
     */
    public function scheduleNexusOperation(
        string $operationId,
        \Gplanchat\Durable\Nexus\NexusEndpoint $endpoint,
        \Gplanchat\Durable\Nexus\NexusService $service,
        \Gplanchat\Durable\Nexus\NexusOperationName $operation,
        array $payload,
        \Gplanchat\Durable\Nexus\NexusOperationTimeouts $timeouts,
        \Gplanchat\Durable\Nexus\NexusOperationHeaders $headers,
    ): void;

    /**
     * Requests the cancellation of a Nexus operation still in flight.
     *
     * @throws \Gplanchat\Durable\Nexus\NexusUnsupportedByBackendException if the backend cannot route the call
     */
    public function cancelNexusOperation(string $operationId, string $reason): void;

    public function cancelActivity(string $activityId, string $reason): void;

    /**
     * Records a timer cancellation (COMMAND_TYPE_CANCEL_TIMER for Temporal).
     */
    public function cancelTimer(string $timerId, string $reason): void;
}
