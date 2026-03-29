<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Worker;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\ActivityExecutor;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Failure\ActivityFailureEventFactory;
use Gplanchat\Durable\Port\WorkflowResumeDispatcher;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityMessage;
use Gplanchat\Durable\Transport\ActivityTransportInterface;

/**
 * Traite un {@see ActivityMessage} : timeouts, exécution, journal, reprise workflow, retry.
 *
 * Réutilisable par le bundle Symfony ({@see \Gplanchat\Durable\Bundle\Command\ActivityWorkerCommand})
 * et par d’autres runtimes (ex. module Magento en mode DBAL).
 */
final class ActivityMessageProcessor
{
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityTransportInterface $activityTransport,
        private readonly ActivityExecutor $activityExecutor,
        private readonly WorkflowResumeDispatcher $resumeDispatcher,
        private readonly int $maxRetries = 0,
    ) {
    }

    public function process(ActivityMessage $message): void
    {
        $options = ActivityOptions::fromMetadata($message->metadata);
        $now = microtime(true);
        $firstQueued = isset($message->metadata['first_queued_at']) ? (float) $message->metadata['first_queued_at'] : null;

        if (null !== $options && null !== $firstQueued) {
            if (null !== $options->scheduleToCloseTimeoutSeconds && $options->scheduleToCloseTimeoutSeconds > 0
                && ($now - $firstQueued) > $options->scheduleToCloseTimeoutSeconds) {
                $this->appendActivityFailure($message, new \RuntimeException('Activity schedule-to-close timeout exceeded.'));

                return;
            }
            if ($message->attempt() <= 1
                && null !== $options->scheduleToStartTimeoutSeconds && $options->scheduleToStartTimeoutSeconds > 0
                && ($now - $firstQueued) > $options->scheduleToStartTimeoutSeconds) {
                $this->appendActivityFailure($message, new \RuntimeException('Activity schedule-to-start timeout exceeded.'));

                return;
            }
        }

        try {
            $stc = $options?->startToCloseTimeoutSeconds;
            if (null !== $stc && $stc > 0) {
                set_time_limit(max(1, (int) ceil($stc)));
            }
            try {
                $result = $this->activityExecutor->execute($message->activityName, $message->payload);
            } finally {
                if (null !== $stc && $stc > 0) {
                    ini_restore('max_execution_time');
                }
            }
            $this->eventStore->append(new ActivityCompleted(
                $message->executionId,
                $message->activityId,
                $result,
            ));
            $this->resumeDispatcher->dispatchResume($message->executionId);
        } catch (\Throwable $e) {
            $maxAttempts = null !== $options && $options->maxAttempts > 0
                ? $options->maxAttempts
                : $this->maxRetries;
            $shouldRetry = $maxAttempts > 0 && $message->attempt() <= $maxAttempts
                && (null === $options || !$options->isNonRetryable($e));

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
                $this->appendActivityFailure($message, $e);
            }
        }
    }

    private function appendActivityFailure(ActivityMessage $message, \Throwable $e): void
    {
        $this->eventStore->append(ActivityFailureEventFactory::fromActivityThrowable(
            $message->executionId,
            $message->activityId,
            $message->activityName,
            $message->attempt(),
            $e,
        ));
        $this->resumeDispatcher->dispatchResume($message->executionId);
    }
}
