<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Worker;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\ActivityExecutor;
use Gplanchat\Durable\Debug\WorkflowExecutionObserverInterface;
use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityTaskCompleted;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Failure\ActivityFailureEventFactory;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\Port\ActivityHeartbeatSenderInterface;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\ActivityEventJournal;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;
use Gplanchat\Durable\Transport\NoopActivityTransport;

/**
 * Traite un {@see ActivityMessage} : timeouts, exécution, journal, reprise workflow, retry.
 *
 * Réutilisable par le bundle Symfony ({@see \Gplanchat\Durable\Bundle\Handler\ActivityRunHandler})
 * et par d’autres runtimes (workers consommant la même abstraction transport).
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
    ) {
    }

    public function process(ActivityMessage $message): void
    {
        if (ActivityEventJournal::hasTerminalOutcomeForActivity(
            $this->eventStore,
            $message->executionId,
            $message->activityId,
        )) {
            return;
        }

        $options = ActivityOptions::fromMetadata($message->metadata);
        $now = microtime(true);
        $firstQueued = isset($message->metadata['first_queued_at']) ? (float) $message->metadata['first_queued_at'] : null;

        if (null !== $options && null !== $firstQueued) {
            if (null !== $options->scheduleToCloseTimeoutSeconds && $options->scheduleToCloseTimeoutSeconds > 0
                && ($now - $firstQueued) > $options->scheduleToCloseTimeoutSeconds) {
                $this->appendActivityFailure($message, new \RuntimeException('Activity schedule-to-close timeout exceeded.'), ActivityRetryState::Timeout);

                return;
            }
            if ($message->attempt() <= 1
                && null !== $options->scheduleToStartTimeoutSeconds && $options->scheduleToStartTimeoutSeconds > 0
                && ($now - $firstQueued) > $options->scheduleToStartTimeoutSeconds) {
                $this->appendActivityFailure($message, new \RuntimeException('Activity schedule-to-start timeout exceeded.'), ActivityRetryState::Timeout);

                return;
            }
        }

        try {
            if (true === $this->heartbeatSender->isCancellationRequested()) {
                $this->appendActivityCancelled($message, 'cancellation_requested');

                return;
            }

            $stc = $options?->startToCloseTimeoutSeconds;
            if (null !== $stc && $stc > 0) {
                set_time_limit(max(1, (int) ceil($stc)));
            }
            try {
                if (!ActivityEventJournal::hasActivityTaskStartedForAttempt(
                    $this->eventStore,
                    $message->executionId,
                    $message->activityId,
                    $message->attempt(),
                )) {
                    $this->eventStore->append(new ActivityTaskStarted(
                        $message->executionId,
                        $message->activityId,
                        $message->activityName,
                        $message->attempt(),
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
                    $this->appendActivityCancelled($message, 'cancellation_requested');

                    return;
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
            } finally {
                if (null !== $stc && $stc > 0) {
                    ini_restore('max_execution_time');
                }
            }
            $this->eventStore->append(new ActivityTaskCompleted(
                $message->executionId,
                $message->activityId,
                $result,
            ));
            $this->eventStore->append(new ActivityCompleted(
                $message->executionId,
                $message->activityId,
                $result,
            ));
            $this->resumeDispatcher->dispatchResume($message->executionId);
        } catch (\Throwable $e) {
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
            // `maxRetries` (défaut bundle `max_activity_retries`) compte les *retentatives* ;
            // `ActivityOptions::maxAttempts` compte les *tentatives* au total (sémantique Temporal).
            // Tout est normalisé en nombre total de tentatives avant comparaison.
            $maxAttempts = null !== $options && $options->maxAttempts > 0
                ? $options->maxAttempts
                : ($this->maxRetries > 0 ? $this->maxRetries + 1 : 0);

            $nonRetryable = null !== $options && $options->isNonRetryable($e);
            $shouldRetry = !$nonRetryable && $maxAttempts > 0 && $message->attempt() < $maxAttempts;

            // Le transport ne retente pas côté PHP (worker Temporal natif) : le serveur Temporal
            // applique sa RetryPolicy. On journalise le VRAI échec en `InProgress` — un échec
            // synthétique masquerait le type d'exception attendu par `nonRetryableErrorTypes`.
            $delegatedToTransport = $shouldRetry && $this->activityTransport instanceof NoopActivityTransport;

            $retryState = match (true) {
                $delegatedToTransport, $shouldRetry => ActivityRetryState::InProgress,
                $nonRetryable => ActivityRetryState::NonRetryableFailure,
                $maxAttempts > 0 => ActivityRetryState::MaximumAttemptsReached,
                default => ActivityRetryState::RetryPolicyNotSet,
            };

            $this->eventStore->append(ActivityTaskFailed::forThrowable(
                $message->executionId,
                $message->activityId,
                $message->activityName,
                $message->attempt(),
                $e,
                $retryState,
            ));

            if ($delegatedToTransport) {
                $this->appendActivityFailure($message, $e, ActivityRetryState::InProgress);

                return;
            }

            if ($shouldRetry) {
                $nextAttempt = $message->attempt() + 1;
                $meta = $message->metadata;
                $meta['attempt'] = $nextAttempt;
                $delay = null !== $options ? $options->retryDelayBeforeAttempt($nextAttempt) : 0.0;
                if ($delay > 0) {
                    $meta['retry_delay_seconds'] = $delay;
                }
                $this->activityTransport->enqueue(new ActivityMessage(
                    $message->executionId,
                    $message->activityId,
                    $message->activityName,
                    $message->payload,
                    $meta,
                ));
            } else {
                $this->appendActivityFailure($message, $e, $retryState);
            }
        }
    }

    private function appendActivityFailure(ActivityMessage $message, \Throwable $e, ActivityRetryState $retryState): void
    {
        $this->eventStore->append(ActivityFailureEventFactory::fromActivityThrowable(
            $message->executionId,
            $message->activityId,
            $message->activityName,
            $message->attempt(),
            $e,
            $retryState,
        ));
        $this->resumeDispatcher->dispatchResume($message->executionId);
    }

    private function appendActivityCancelled(ActivityMessage $message, string $reason): void
    {
        $this->eventStore->append(new ActivityCancelled(
            $message->executionId,
            $message->activityId,
            $reason,
        ));
        $this->resumeDispatcher->dispatchResume($message->executionId);
    }
}
