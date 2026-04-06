<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Read-only access to recorded workflow history for slot-based replay.
 *
 * Each "slot" is a sequential index within a family of operations (activities, timers, signals, etc.).
 * The in-memory backend implements this over EventStoreInterface; the Temporal backend implements this
 * over TemporalHistoryCursor-built TemporalExecutionHistory.
 */
interface WorkflowHistorySourceInterface
{
    /**
     * Returns the recorded result for activity slot N, or null if not yet recorded.
     *
     * @return array{result: mixed, failed: \Throwable|null}|null
     */
    public function findActivitySlotResult(int $slot): ?array;

    /**
     * Returns the activity ID that was scheduled at slot N (first-occurrence order), or null.
     */
    public function findScheduledActivityId(int $slot): ?string;

    /**
     * Returns the recorded result for timer slot N, or null if not yet fired.
     *
     * @return array{id: string, scheduledAt: float}|null
     */
    public function findTimerSlotResult(int $slot): ?array;

    /**
     * Returns the timer ID that was scheduled at slot N, or null.
     */
    public function findScheduledTimerId(int $slot): ?string;

    /**
     * Returns the recorded side effect result at slot N, or null if not yet recorded.
     */
    public function findSideEffectForSlot(int $slot): mixed;

    /**
     * Returns the recorded result for child workflow slot N, or null if not yet completed.
     *
     * @return array{childExecutionId: string, result: mixed, failed: \Throwable|null}|null
     */
    public function findChildWorkflowForSlot(int $slot): ?array;

    /**
     * Returns the child execution ID scheduled at slot N, or null.
     */
    public function findScheduledChildExecutionId(int $slot): ?string;

    /**
     * Returns the payload of the signal received at signal slot N for the given signal name, or null.
     *
     * @return array{payload: mixed}|null
     */
    public function findSignalForSlot(string $signalName, int $slot): ?array;

    /**
     * Returns the result of the update handled at update slot N for the given update name, or null.
     *
     * @return array{result: mixed}|null
     */
    public function findUpdateForSlot(string $updateName, int $slot): ?array;

    /**
     * Returns whether the given child execution ID has already been scheduled (for reuse policy checks).
     */
    public function hasChildExecutionId(string $childExecutionId): bool;

    /**
     * Returns whether the given child execution has completed successfully.
     */
    public function hasChildExecutionCompletedSuccessfully(string $childExecutionId): bool;
}
