<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Worker;

use Gplanchat\Durable\Activity\RetryLimit;
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
    ) {}

    public function process(ActivityMessage $message): void
    {
        if (ActivityEventJournal::hasTerminalOutcomeForActivity(
            $this->eventStore,
            $message->executionId,
            $message->activityId,
        )) {
            return;
        }

        $options = $message->options;
        $now = microtime(true);
        $firstQueued = $message->firstQueuedAt;

        if (null !== $options && null !== $firstQueued) {
            $timeouts = $options->timeouts;
            if ($timeouts->scheduleToClose?->hasElapsedSince($firstQueued, $now)) {
                $this->appendActivityFailure($message, new \RuntimeException('Activity schedule-to-close timeout exceeded.'), ActivityRetryState::Timeout);

                return;
            }
            if ($message->attempt <= 1 && $timeouts->scheduleToStart?->hasElapsedSince($firstQueued, $now)) {
                $this->appendActivityFailure($message, new \RuntimeException('Activity schedule-to-start timeout exceeded.'), ActivityRetryState::Timeout);

                return;
            }
        }

        try {
            if (true === $this->heartbeatSender->isCancellationRequested()) {
                $this->appendActivityCancelled($message, 'cancellation_requested');

                return;
            }

            $startToClose = $options?->timeouts->startToClose;
            if (null !== $startToClose) {
                set_time_limit(max(1, (int) ceil($startToClose->toSeconds())));
            }

            try {
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
                if (null !== $startToClose) {
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
            // La borne de l'activité et le plafond de l'application se composent : la plus
            // stricte des deux l'emporte.
            $retryLimit = (null !== $options ? $options->retryLimit : RetryLimit::unlimited())
                ->narrowedTo(RetryLimit::ofRetries($this->maxRetries));

            $nonRetryable = null !== $options && $options->isNonRetryable($e);
            $shouldRetry = !$nonRetryable && $retryLimit->allowsAttempt($message->attempt + 1);

            // Le transport ne retente pas côté PHP (worker Temporal natif) : l'autorité sur les
            // retentatives appartient entièrement au serveur, donc le décompte de tentatives PHP
            // n'y veut rien dire — seule la non-retryabilité, sur laquelle le serveur s'aligne via
            // nonRetryableErrorTypes, reste terminale. On journalise le VRAI échec en `InProgress` :
            // un échec terminal court-circuiterait la tentative suivante côté worker.
            $delegatedToTransport = !$nonRetryable && $this->activityTransport instanceof NoopActivityTransport;

            $retryState = match (true) {
                $delegatedToTransport, $shouldRetry => ActivityRetryState::InProgress,
                // (l'ordre compte : `InProgress` l'emporte sur le décompte local)
                $nonRetryable => ActivityRetryState::NonRetryableFailure,
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

                return;
            }

            if ($shouldRetry) {
                $delay = $options?->retryDelayBeforeAttempt($message->attempt + 1);
                $this->activityTransport->enqueue(
                    $message->retryingIn(null !== $delay && !$delay->isZero() ? $delay : null),
                );
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
            $message->attempt,
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
