<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Read-only access to recorded workflow history for slot-based replay.
 *
 * Recorded timings are returned as {@see \Gplanchat\Durable\Duration}, so a value read from
 * history and a value about to be written are the same kind of thing. Third-party implementations
 * written against the previous `float` return must adapt. See ADR DUR031.
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
     * Returns the recorded outcome for timer slot N, or null if it is still pending.
     *
     * `failed` porte l'annulation du minuteur ({@see \Gplanchat\Durable\Event\TimerCancelled}) :
     * sans ce canal, un minuteur annulé par l'annulation du workflow ne pouvait pas relever la
     * même exception au replay.
     *
     * @return array{id: string, scheduledAt: float, failed: \Throwable|null}|null
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
     * Slots are counted over the signals *of that name*, in recorded order.
     *
     * `$notAfterTimerId` bounds the lookup: a signal recorded after that timer fired does not
     * settle the wait the timer bounded. The verdict of a deadline is a function of history
     * order, never of the clock of the process performing the replay — see ADR DUR032.
     *
     * @return array{payload: mixed}|null
     */
    public function findSignalForSlot(string $signalName, int $slot, ?string $notAfterTimerId = null): ?array;

    /**
     * Returns the result of the update handled at update slot N for the given update name, or null.
     *
     * @return array{result: mixed}|null
     */
    public function findUpdateForSlot(string $updateName, int $slot): ?array;

    /**
     * Returns the Nth recorded message (signal), in recorded order, or null past the end.
     *
     * `position` is the rank of the event in this execution's recorded history — the stream index
     * in memory, the `eventId` on Temporal. Positions are comparable **within one execution's own
     * history** and nowhere else: they are never serialized, and never compared across backends.
     * See ADR DUR033.
     *
     * @return array{position: int, name: string, payload: array<string, mixed>}|null
     */
    public function messageAt(int $index): ?array;

    /**
     * Returns the position at which the given timer's completion was recorded, or null if it has
     * not fired. Compared against {@see messageAt()} positions to decide whether a message landed
     * before or after a deadline.
     */
    public function timerCompletionPosition(string $timerId): ?int;

    /**
     * Returns whether the given child execution ID has already been scheduled (for reuse policy checks).
     */
    public function hasChildExecutionId(string $childExecutionId): bool;

    /**
     * Returns whether the given child execution has completed successfully.
     */
    public function hasChildExecutionCompletedSuccessfully(string $childExecutionId): bool;
}
