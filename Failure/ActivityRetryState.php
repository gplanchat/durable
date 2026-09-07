<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Failure;

/**
 * Why an activity stopped being retried (discriminant carried by {@see \Gplanchat\Durable\Event\ActivityFailed}).
 *
 * Aligned on {@see \Temporal\Api\Enums\V1\RetryState}: Temporal models this state as a **field**
 * of `ActivityTaskFailedEventAttributes` / `ActivityFailureInfo`, not as a distinct event type.
 */
enum ActivityRetryState: string
{
    /** A new attempt is scheduled (carried by {@see \Gplanchat\Durable\Event\ActivityTaskFailed}). */
    case InProgress = 'in_progress';

    /** The exception is one of {@see \Gplanchat\Durable\Activity\ActivityOptions::$nonRetryableExceptions}. */
    case NonRetryableFailure = 'non_retryable_failure';

    /** Schedule-to-start / schedule-to-close timeout: no further attempt is allowed. */
    case Timeout = 'timeout';

    /** Every allowed attempt has been consumed — "ActivityStalled". */
    case MaximumAttemptsReached = 'maximum_attempts_reached';

    /**
     * No retry policy active on the server side. No longer produced locally since
     * {@see \Gplanchat\Durable\Activity\RetryLimit::unlimited()} became the default; still read
     * back from the Temporal history (`RETRY_STATE_RETRY_POLICY_NOT_SET`).
     */
    case RetryPolicyNotSet = 'retry_policy_not_set';

    /**
     * PHP-side retrying is disabled by the transport ({@see \Gplanchat\Durable\Transport\NoopActivityTransport},
     * native Temporal worker): the journalled failure is **synthetic**, the real cause is carried by Temporal.
     */
    case TransportRetryDisabled = 'transport_retry_disabled';

    /**
     * True if the event describes a definitive stop following a genuine business failure
     * (as opposed to an infrastructure marker).
     */
    public function isTerminalBusinessFailure(): bool
    {
        return match ($this) {
            self::NonRetryableFailure, self::MaximumAttemptsReached, self::Timeout, self::RetryPolicyNotSet => true,
            self::InProgress, self::TransportRetryDisabled => false,
        };
    }
}
