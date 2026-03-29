<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

/**
 * Options de planification d’activité (équivalent {@see \Temporal\Activity\ActivityOptions}).
 *
 * Les timeouts sont exprimés en secondes (fractionnaires). La politique de retry reprend
 * {@see \Temporal\Common\RetryOptions} (champs aplatis pour la sérialisation journal / message).
 */
final readonly class ActivityOptions
{
    /**
     * @param list<class-string<\Throwable>> $nonRetryableExceptions
     */
    public function __construct(
        /** Nombre max de tentatives (0 = illimité côté worker, avec plafond bundle). */
        public int $maxAttempts = 0,
        /** Délai avant la première retentative après un échec (secondes). */
        public float $initialIntervalSeconds = 1.0,
        /** Coefficient d’exponential backoff entre retentatives. */
        public float $backoffCoefficient = 2.0,
        /** Plafond du délai entre deux retentatives (secondes). */
        public ?float $maximumIntervalSeconds = null,
        /** Exceptions qui ne déclenchent pas de retry (class-string[]). */
        public array $nonRetryableExceptions = [],
        /** File d’attente cible (routage applicatif ; non utilisée par tous les transports). */
        public ?string $taskQueue = null,
        /** ID métier d’activité (sinon UUID). */
        public ?string $activityId = null,
        public ?float $scheduleToCloseTimeoutSeconds = null,
        public ?float $scheduleToStartTimeoutSeconds = null,
        public ?float $startToCloseTimeoutSeconds = null,
        public ?float $heartbeatTimeoutSeconds = null,
        public ActivityCancellationType $cancellationType = ActivityCancellationType::TryCancel,
        /** Résumé affichage UI (champ « summary » côté Temporal). */
        public ?string $summary = null,
    ) {
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * Délai à appliquer **avant** la tentative n° {@code $nextAttempt} (1-based), après l’échec de la tentative précédente.
     * Pour {@code $nextAttempt} &lt;= 1, retourne 0.
     */
    public function retryDelayBeforeAttempt(int $nextAttempt): float
    {
        if ($nextAttempt <= 1) {
            return 0.0;
        }
        $exponent = $nextAttempt - 2;
        $delay = $this->initialIntervalSeconds * ($this->backoffCoefficient ** $exponent);
        if (null !== $this->maximumIntervalSeconds && $this->maximumIntervalSeconds > 0) {
            $delay = min($delay, $this->maximumIntervalSeconds);
        }

        return $delay;
    }

    public function withMaxAttempts(int $maxAttempts): self
    {
        return new self(
            $maxAttempts,
            $this->initialIntervalSeconds,
            $this->backoffCoefficient,
            $this->maximumIntervalSeconds,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $this->activityId,
            $this->scheduleToCloseTimeoutSeconds,
            $this->scheduleToStartTimeoutSeconds,
            $this->startToCloseTimeoutSeconds,
            $this->heartbeatTimeoutSeconds,
            $this->cancellationType,
            $this->summary,
        );
    }

    public function withInitialInterval(float $seconds): self
    {
        return new self(
            $this->maxAttempts,
            $seconds,
            $this->backoffCoefficient,
            $this->maximumIntervalSeconds,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $this->activityId,
            $this->scheduleToCloseTimeoutSeconds,
            $this->scheduleToStartTimeoutSeconds,
            $this->startToCloseTimeoutSeconds,
            $this->heartbeatTimeoutSeconds,
            $this->cancellationType,
            $this->summary,
        );
    }

    public function withBackoffCoefficient(float $coefficient): self
    {
        return new self(
            $this->maxAttempts,
            $this->initialIntervalSeconds,
            $coefficient,
            $this->maximumIntervalSeconds,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $this->activityId,
            $this->scheduleToCloseTimeoutSeconds,
            $this->scheduleToStartTimeoutSeconds,
            $this->startToCloseTimeoutSeconds,
            $this->heartbeatTimeoutSeconds,
            $this->cancellationType,
            $this->summary,
        );
    }

    public function withMaximumInterval(?float $seconds): self
    {
        return new self(
            $this->maxAttempts,
            $this->initialIntervalSeconds,
            $this->backoffCoefficient,
            $seconds,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $this->activityId,
            $this->scheduleToCloseTimeoutSeconds,
            $this->scheduleToStartTimeoutSeconds,
            $this->startToCloseTimeoutSeconds,
            $this->heartbeatTimeoutSeconds,
            $this->cancellationType,
            $this->summary,
        );
    }

    /**
     * @param class-string[] $exceptions
     */
    public function withNonRetryableExceptions(array $exceptions): self
    {
        return new self(
            $this->maxAttempts,
            $this->initialIntervalSeconds,
            $this->backoffCoefficient,
            $this->maximumIntervalSeconds,
            $exceptions,
            $this->taskQueue,
            $this->activityId,
            $this->scheduleToCloseTimeoutSeconds,
            $this->scheduleToStartTimeoutSeconds,
            $this->startToCloseTimeoutSeconds,
            $this->heartbeatTimeoutSeconds,
            $this->cancellationType,
            $this->summary,
        );
    }

    public function withTaskQueue(?string $taskQueue): self
    {
        return new self(
            $this->maxAttempts,
            $this->initialIntervalSeconds,
            $this->backoffCoefficient,
            $this->maximumIntervalSeconds,
            $this->nonRetryableExceptions,
            $taskQueue,
            $this->activityId,
            $this->scheduleToCloseTimeoutSeconds,
            $this->scheduleToStartTimeoutSeconds,
            $this->startToCloseTimeoutSeconds,
            $this->heartbeatTimeoutSeconds,
            $this->cancellationType,
            $this->summary,
        );
    }

    public function withActivityId(?string $activityId): self
    {
        return new self(
            $this->maxAttempts,
            $this->initialIntervalSeconds,
            $this->backoffCoefficient,
            $this->maximumIntervalSeconds,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $activityId,
            $this->scheduleToCloseTimeoutSeconds,
            $this->scheduleToStartTimeoutSeconds,
            $this->startToCloseTimeoutSeconds,
            $this->heartbeatTimeoutSeconds,
            $this->cancellationType,
            $this->summary,
        );
    }

    public function withScheduleToCloseTimeoutSeconds(?float $seconds): self
    {
        return new self(
            $this->maxAttempts,
            $this->initialIntervalSeconds,
            $this->backoffCoefficient,
            $this->maximumIntervalSeconds,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $this->activityId,
            $seconds,
            $this->scheduleToStartTimeoutSeconds,
            $this->startToCloseTimeoutSeconds,
            $this->heartbeatTimeoutSeconds,
            $this->cancellationType,
            $this->summary,
        );
    }

    public function withScheduleToStartTimeoutSeconds(?float $seconds): self
    {
        return new self(
            $this->maxAttempts,
            $this->initialIntervalSeconds,
            $this->backoffCoefficient,
            $this->maximumIntervalSeconds,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $this->activityId,
            $this->scheduleToCloseTimeoutSeconds,
            $seconds,
            $this->startToCloseTimeoutSeconds,
            $this->heartbeatTimeoutSeconds,
            $this->cancellationType,
            $this->summary,
        );
    }

    public function withStartToCloseTimeoutSeconds(?float $seconds): self
    {
        return new self(
            $this->maxAttempts,
            $this->initialIntervalSeconds,
            $this->backoffCoefficient,
            $this->maximumIntervalSeconds,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $this->activityId,
            $this->scheduleToCloseTimeoutSeconds,
            $this->scheduleToStartTimeoutSeconds,
            $seconds,
            $this->heartbeatTimeoutSeconds,
            $this->cancellationType,
            $this->summary,
        );
    }

    public function withHeartbeatTimeoutSeconds(?float $seconds): self
    {
        return new self(
            $this->maxAttempts,
            $this->initialIntervalSeconds,
            $this->backoffCoefficient,
            $this->maximumIntervalSeconds,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $this->activityId,
            $this->scheduleToCloseTimeoutSeconds,
            $this->scheduleToStartTimeoutSeconds,
            $this->startToCloseTimeoutSeconds,
            $seconds,
            $this->cancellationType,
            $this->summary,
        );
    }

    public function withCancellationType(ActivityCancellationType $type): self
    {
        return new self(
            $this->maxAttempts,
            $this->initialIntervalSeconds,
            $this->backoffCoefficient,
            $this->maximumIntervalSeconds,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $this->activityId,
            $this->scheduleToCloseTimeoutSeconds,
            $this->scheduleToStartTimeoutSeconds,
            $this->startToCloseTimeoutSeconds,
            $this->heartbeatTimeoutSeconds,
            $type,
            $this->summary,
        );
    }

    public function withSummary(?string $summary): self
    {
        return new self(
            $this->maxAttempts,
            $this->initialIntervalSeconds,
            $this->backoffCoefficient,
            $this->maximumIntervalSeconds,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $this->activityId,
            $this->scheduleToCloseTimeoutSeconds,
            $this->scheduleToStartTimeoutSeconds,
            $this->startToCloseTimeoutSeconds,
            $this->heartbeatTimeoutSeconds,
            $this->cancellationType,
            $summary,
        );
    }

    /**
     * @return array<string, mixed> Métadonnées pour ActivityScheduled / ActivityMessage
     */
    public function toMetadata(): array
    {
        $activityOptions = [
            'max_attempts' => $this->maxAttempts,
            'initial_interval_seconds' => $this->initialIntervalSeconds,
            'backoff_coefficient' => $this->backoffCoefficient,
            'non_retryable_exceptions' => $this->nonRetryableExceptions,
            'cancellation_type' => $this->cancellationType->value,
        ];
        if (null !== $this->maximumIntervalSeconds) {
            $activityOptions['maximum_interval_seconds'] = $this->maximumIntervalSeconds;
        }
        if (null !== $this->taskQueue && '' !== $this->taskQueue) {
            $activityOptions['task_queue'] = $this->taskQueue;
        }
        if (null !== $this->activityId && '' !== $this->activityId) {
            $activityOptions['activity_id'] = $this->activityId;
        }
        if (null !== $this->scheduleToCloseTimeoutSeconds) {
            $activityOptions['schedule_to_close_timeout_seconds'] = $this->scheduleToCloseTimeoutSeconds;
        }
        if (null !== $this->scheduleToStartTimeoutSeconds) {
            $activityOptions['schedule_to_start_timeout_seconds'] = $this->scheduleToStartTimeoutSeconds;
        }
        if (null !== $this->startToCloseTimeoutSeconds) {
            $activityOptions['start_to_close_timeout_seconds'] = $this->startToCloseTimeoutSeconds;
        }
        if (null !== $this->heartbeatTimeoutSeconds) {
            $activityOptions['heartbeat_timeout_seconds'] = $this->heartbeatTimeoutSeconds;
        }
        if (null !== $this->summary && '' !== $this->summary) {
            $activityOptions['summary'] = $this->summary;
        }

        return ['activity_options' => $activityOptions];
    }

    /**
     * @param array<string, mixed> $metadata
     */
    public static function fromMetadata(array $metadata): ?self
    {
        $opts = $metadata['activity_options'] ?? null;
        if (!\is_array($opts)) {
            return null;
        }

        $cancellation = ActivityCancellationType::TryCancel;
        if (isset($opts['cancellation_type'])) {
            $v = (int) $opts['cancellation_type'];
            $cancellation = ActivityCancellationType::tryFrom($v) ?? ActivityCancellationType::TryCancel;
        }

        return new self(
            (int) ($opts['max_attempts'] ?? 0),
            (float) ($opts['initial_interval_seconds'] ?? 1.0),
            (float) ($opts['backoff_coefficient'] ?? 2.0),
            isset($opts['maximum_interval_seconds']) ? (float) $opts['maximum_interval_seconds'] : null,
            \is_array($opts['non_retryable_exceptions'] ?? null) ? $opts['non_retryable_exceptions'] : [],
            isset($opts['task_queue']) ? (string) $opts['task_queue'] : null,
            isset($opts['activity_id']) ? (string) $opts['activity_id'] : null,
            isset($opts['schedule_to_close_timeout_seconds']) ? (float) $opts['schedule_to_close_timeout_seconds'] : null,
            isset($opts['schedule_to_start_timeout_seconds']) ? (float) $opts['schedule_to_start_timeout_seconds'] : null,
            isset($opts['start_to_close_timeout_seconds']) ? (float) $opts['start_to_close_timeout_seconds'] : null,
            isset($opts['heartbeat_timeout_seconds']) ? (float) $opts['heartbeat_timeout_seconds'] : null,
            $cancellation,
            isset($opts['summary']) ? (string) $opts['summary'] : null,
        );
    }

    public function isNonRetryable(\Throwable $e): bool
    {
        foreach ($this->nonRetryableExceptions as $exceptionClass) {
            if (is_a($e, $exceptionClass)) {
                return true;
            }
        }

        return false;
    }
}
