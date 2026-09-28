<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Worker;

use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\ActivityExecutor;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityRetryQueued;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Exception\ActivityAttemptDeferred;
use Gplanchat\Durable\Failure\ActivityFailureEventFactory;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\Port\ActivityAttemptClaimInterface;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\NoActivityAttemptClaim;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\ActivityEventJournal;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\AwaitedFact;
use Gplanchat\Durable\Transport\NoopActivityTransport;

/**
 * Processes an {@see ActivityMessage}: timeouts, execution, journal, workflow resume, retry.
 *
 * Reusable by the Symfony bundle ({@see \Gplanchat\Durable\Bundle\Handler\ActivityRunHandler})
 * and by other runtimes (workers consuming the same transport abstraction).
 */
final class ActivityMessageProcessor
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityTransportInterface $activityTransport,
        private readonly ActivityExecutor $activityExecutor,
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
        private readonly ActivityHeartbeatSenderInterface $heartbeatSender,
        private readonly int $maxRetries = 0,
        private readonly ?WorkflowExecutionObserverInterface $workflowExecutionObserver = null,
        private readonly ActivityAttemptClaimInterface $attemptClaim = new NoActivityAttemptClaim(),
    ) {}

    /**
     * @return \Throwable|null the failure that ended the activity because it is declared
     *                         non-retryable, for a host whose queue must not retry it either (#341);
     *                         null otherwise — it is already journalled either way
     */
    public function process(ActivityMessage $message): ?\Throwable
    {
        // A copy of an attempt another worker holds: not now, and not never, since a holder that
        // died keeps its claim until the lock TTL. The host delivers it again later (#590).
        $release = $this->attemptClaim->claim($message->executionId, $message->activityId, $message->attempt);
        if (null === $release) {
            throw new ActivityAttemptDeferred($message->executionId, $message->activityId, $message->attempt);
        }

        try {
            return $this->processClaimed($message);
        } finally {
            $release();
        }
    }

    private function processClaimed(ActivityMessage $message): ?\Throwable
    {
        // A redelivery of an attempt that already ran is answered by the journal, not run again:
        // re-running a failed attempt would also queue its retry a second time (#319). An outcome
        // was followed by a resume, which may be the very send that failed and caused this
        // redelivery: it goes again, resumes being at-least-once (#328).
        if (null !== ActivityEventJournal::settledOutcomeForDelivery(
            $this->eventStore,
            $message->executionId,
            $message->activityId,
            $message->attempt,
        )) {
            $this->resumeDispatcher->dispatchResume($message->executionId);

            return null;
        }
        if (ActivityEventJournal::hasActivityTaskFailedForAttempt(
            $this->eventStore,
            $message->executionId,
            $message->activityId,
            $message->attempt,
        )) {
            // Queueing the retry may be what failed and caused this redelivery: the journal says
            // whether it went out, as Temporal's dispatch task does (#590).
            if (!$this->activityTransport instanceof NoopActivityTransport
                && ActivityEventJournal::nextAttemptIsDue($this->eventStore, $message->executionId, $message->activityId, $message->attempt)
            ) {
                $this->enqueueNextAttempt($message);
            }

            return null;
        }

        $options = $message->options;
        $now = microtime(true);
        $firstQueued = $message->firstQueuedAt;

        if (null !== $options && null !== $firstQueued) {
            $timeouts = $options->timeouts;
            if ($timeouts->scheduleToClose?->hasElapsedSince($firstQueued, $now)) {
                $this->appendActivityFailure($message, new \RuntimeException('Activity schedule-to-close timeout exceeded.'), ActivityRetryState::Timeout);

                return null;
            }
            if ($message->attempt <= 1 && $timeouts->scheduleToStart?->hasElapsedSince($firstQueued, $now)) {
                $this->appendActivityFailure($message, new \RuntimeException('Activity schedule-to-start timeout exceeded.'), ActivityRetryState::Timeout);

                return null;
            }
        }

        $timedOut = false;
        // Once the attempt has its outcome, what follows (journalling it, sending the resume) is not
        // the activity's doing: an error there fails the message, which is redelivered, rather than
        // the attempt, which succeeded (#328).
        $settled = false;

        try {
            if (true === $this->heartbeatSender->isCancellationRequested()) {
                $settled = true;
                $this->appendActivityCancelled($message, 'cancellation_requested');

                return null;
            }

            if (!ActivityEventJournal::hasActivityTaskStartedForAttempt(
                $this->eventStore,
                $message->executionId,
                $message->activityId,
                $message->attempt,
            )) {
                $this->eventStore->append(new ActivityTaskStarted(
                    $message->executionId,
                    $message->activityId,
                    $message->activityName,
                    $message->attempt,
                ));
            }
            $t0 = microtime(true);
            $result = $this->activityExecutor->execute($message->activityName, $message->payload);
            if (true === $this->heartbeatSender->isCancellationRequested()) {
                $duration = microtime(true) - $t0;
                $this->workflowExecutionObserver?->onActivityExecuted(
                    $message->executionId,
                    $message->activityId,
                    $message->activityName,
                    $duration,
                    false,
                    null,
                );
                $settled = true;
                $this->appendActivityCancelled($message, 'cancellation_requested');

                return null;
            }
            // Measured after the fact, not enforced with a PHP time limit: that one counts CPU
            // time only, so a stalled call never trips it, and when it does trip it kills the
            // worker before anything is journalled. Stopping a runaway attempt is the host's job.
            if ($options?->timeouts->startToClose?->hasElapsedSince($t0, microtime(true))) {
                $timedOut = true;

                throw new \RuntimeException('Activity start-to-close timeout exceeded.');
            }
            $duration = microtime(true) - $t0;
            $this->workflowExecutionObserver?->onActivityExecuted(
                $message->executionId,
                $message->activityId,
                $message->activityName,
                $duration,
                true,
                null,
            );
            // One event on success (#262): the attempt's result is the settled result, and a second
            // `ActivityTaskCompleted` with the same body only doubled the timeline row. Failures keep
            // the split, one `ActivityTaskFailed` per attempt for one outcome.
            $settled = true;
            // Sent before the append and again after (DUR050): a worker that dies in between leaves
            // a resume that waits for the outcome, instead of an outcome nobody resumes.
            $this->resumeDispatcher->dispatchResumeAwaiting($message->executionId, AwaitedFact::activity($message->activityId));
            $this->eventStore->append(new ActivityCompleted(
                $message->executionId,
                $message->activityId,
                $result,
            ));
            $this->resumeDispatcher->dispatchResume($message->executionId);
        } catch (\Throwable $e) {
            if ($settled) {
                throw $e;
            }
            if (isset($t0)) {
                $duration = microtime(true) - $t0;
                $this->workflowExecutionObserver?->onActivityExecuted(
                    $message->executionId,
                    $message->activityId,
                    $message->activityName,
                    $duration,
                    false,
                    $e::class,
                );
            }
            // The activity's bound and the application's ceiling compose: the stricter of the
            // two wins.
            $retryLimit = (null !== $options ? $options->retryLimit : RetryLimit::unlimited())
                ->narrowedTo(RetryLimit::ofRetries($this->maxRetries));

            $nonRetryable = !$timedOut && null !== $options && $options->isNonRetryable($e);
            $shouldRetry = !$nonRetryable && $retryLimit->allowsAttempt($message->attempt + 1);

            // The transport does not retry on the PHP side (native Temporal worker): authority
            // over retries belongs entirely to the server, so the PHP attempt count means nothing
            // there — only non-retryability, on which the server aligns via nonRetryableErrorTypes,
            // stays terminal. The REAL failure is journalled as `InProgress`: a terminal failure
            // would short-circuit the next attempt on the worker side.
            $delegatedToTransport = !$nonRetryable && $this->activityTransport instanceof NoopActivityTransport;

            $retryState = match (true) {
                $delegatedToTransport, $shouldRetry => ActivityRetryState::InProgress,
                // (order matters: `InProgress` wins over the local count)
                $nonRetryable => ActivityRetryState::NonRetryableFailure,
                $timedOut => ActivityRetryState::Timeout,
                default => ActivityRetryState::MaximumAttemptsReached,
            };

            $this->eventStore->append(ActivityTaskFailed::forThrowable(
                $message->executionId,
                $message->activityId,
                $message->activityName,
                $message->attempt,
                $e,
                $retryState,
            ));

            if ($delegatedToTransport) {
                $this->appendActivityFailure($message, $e, ActivityRetryState::InProgress);

                return null;
            }

            if ($shouldRetry) {
                $this->enqueueNextAttempt($message);
            } else {
                $this->appendActivityFailure($message, $e, $retryState);

                return $nonRetryable ? $e : null;
            }
        }

        return null;
    }

    private function enqueueNextAttempt(ActivityMessage $message): void
    {
        $delay = $message->options?->retryDelayBeforeAttempt($message->attempt + 1);
        $this->activityTransport->enqueue(
            $message->retryingIn(null !== $delay && !$delay->isZero() ? $delay : null),
        );
        $this->eventStore->append(new ActivityRetryQueued($message->executionId, $message->activityId, $message->attempt + 1));
    }

    private function appendActivityFailure(ActivityMessage $message, \Throwable $e, ActivityRetryState $retryState): void
    {
        $this->resumeDispatcher->dispatchResumeAwaiting($message->executionId, AwaitedFact::activity($message->activityId));
        $this->eventStore->append(ActivityFailureEventFactory::fromActivityThrowable(
            $message->executionId,
            $message->activityId,
            $message->activityName,
            $message->attempt,
            $e,
            $retryState,
        ));
        $this->resumeDispatcher->dispatchResume($message->executionId);
    }

    private function appendActivityCancelled(ActivityMessage $message, string $reason): void
    {
        $this->resumeDispatcher->dispatchResumeAwaiting($message->executionId, AwaitedFact::activity($message->activityId));
        $this->eventStore->append(new ActivityCancelled(
            $message->executionId,
            $message->activityId,
            $reason,
        ));
        $this->resumeDispatcher->dispatchResume($message->executionId);
    }
}
