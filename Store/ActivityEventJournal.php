<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Failure\ActivityRetryState;

/**
 * Detects whether the journal already holds a terminal outcome for a given activity
 * (success, final failure, cancellation). Used to ignore Messenger re-deliveries
 * or duplicate processing without duplicating the events.
 *
 * {@see \Gplanchat\Durable\Event\ActivityTaskFailed} and an {@see ActivityFailed} in
 * {@see ActivityRetryState::InProgress} are **not** terminal: a retry is still expected.
 */
final class ActivityEventJournal
{
    public static function hasTerminalOutcomeForActivity(
        EventStoreInterface $eventStore,
        string $executionId,
        string $activityId,
    ): bool {
        foreach ($eventStore->readStream($executionId) as $event) {
            if ($event instanceof ActivityCompleted && $event->activityId() === $activityId) {
                return true;
            }
            if ($event instanceof ActivityFailed && $event->activityId() === $activityId) {
                // A failure in `InProgress` is journalled when the retry is delegated to the
                // transport (Temporal server): the next attempt must really execute, not be
                // short-circuited by this event.
                if (ActivityRetryState::InProgress === $event->retryState()) {
                    continue;
                }

                return true;
            }
            if ($event instanceof ActivityCatastrophicFailure && $event->activityId() === $activityId) {
                return true;
            }
            if ($event instanceof ActivityCancelled && $event->activityId() === $activityId) {
                return true;
            }
        }

        return false;
    }

    /**
     * The last terminal outcome journalled for an activity, or null if it is still awaiting one.
     *
     * Takes the **last** match: a failure in {@see ActivityRetryState::InProgress} left behind by a
     * previous attempt is thereby superseded by the success of the next one.
     */
    public static function lastTerminalOutcome(
        EventStoreInterface $eventStore,
        string $executionId,
        string $activityId,
    ): ActivityCompleted|ActivityFailed|ActivityCatastrophicFailure|ActivityCancelled|null {
        $last = null;
        foreach ($eventStore->readStream($executionId) as $event) {
            if (!$event instanceof ActivityCompleted
                && !$event instanceof ActivityFailed
                && !$event instanceof ActivityCatastrophicFailure
                && !$event instanceof ActivityCancelled
            ) {
                continue;
            }
            if ($event->activityId() === $activityId) {
                $last = $event;
            }
        }

        return $last;
    }

    /**
     * The outcome that settles **this very delivery**, or `null` if it has to execute.
     *
     * ⚠ This class already carried two notions of "terminal" that contradicted each other.
     * {@see self::hasTerminalOutcomeForActivity()} knows that an `ActivityFailed` in
     * {@see ActivityRetryState::InProgress} is not terminal — the next attempt must really
     * execute — but {@see self::lastTerminalOutcome()} returns the same event without that
     * reservation. The Temporal worker queried the second one **before** processing, so as not to
     * re-execute a re-delivered task: it therefore answered the server with attempt 1's failure
     * for attempts 2 and 3, without ever calling the activity code again. Three attempts burnt in
     * two seconds, the same failure message copied over, and a transient outage turned final.
     *
     * What tells the two cases apart is not the nature of the outcome but **the rank of the
     * attempt that wrote it**: an outcome written for the attempt under way is a re-delivery, to
     * be answered without re-executing; an outcome written for an earlier attempt is a resume,
     * which has to execute.
     *
     * An outcome other than a failure in the middle of a retry is terminal whatever its rank: an
     * activity that is finished, cancelled or irreparably broken is not replayed. A null
     * `retryState` — an old journal, a policy left unfilled — is not `InProgress` and therefore
     * stays terminal: when in doubt, a side effect is not replayed.
     */
    public static function settledOutcomeForDelivery(
        EventStoreInterface $eventStore,
        string $executionId,
        string $activityId,
        int $attempt,
    ): ActivityCompleted|ActivityFailed|ActivityCatastrophicFailure|ActivityCancelled|null {
        $last = self::lastTerminalOutcome($eventStore, $executionId, $activityId);

        if ($last instanceof ActivityFailed
            && ActivityRetryState::InProgress === $last->retryState()
            && $last->failureAttempt() < $attempt
        ) {
            return null;
        }

        return $last;
    }

    /**
     * Returns true if an ActivityTaskStarted event for the given attempt already exists in the
     * journal. Used by ActivityMessageProcessor to avoid recording duplicate task-start events
     * on re-delivery.
     */
    public static function hasActivityTaskStartedForAttempt(
        EventStoreInterface $eventStore,
        string $executionId,
        string $activityId,
        int $attempt,
    ): bool {
        foreach ($eventStore->readStream($executionId) as $event) {
            if ($event instanceof ActivityTaskStarted
                && $event->activityId() === $activityId
                && $event->attempt() === $attempt
            ) {
                return true;
            }
        }

        return false;
    }
}
