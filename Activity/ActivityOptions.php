<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Activity;

/**
 * Options de planification d’activité (équivalent {@see \Temporal\Activity\ActivityOptions}).
 *
 * Trois concepts, pas quatorze champs : jusqu'où réessayer ({@see RetryLimit}), à quel rythme
 * ({@see $initialInterval} / {@see $backoffCoefficient} / {@see $maximumInterval}), et dans
 * quelles bornes temporelles ({@see ActivityTimeouts}).
 *
 * La sérialisation reste plate et en secondes : c'est ce que porte l'historique des exécutions
 * en cours, côté journal comme côté Temporal.
 */
final readonly class ActivityOptions
{
    /** Défaut Temporal du plafond d'intervalle, exprimé en multiples de l'intervalle initial. */
    public const DEFAULT_MAXIMUM_INTERVAL_FACTOR = 100.0;

    /** Jusqu'où l'on est prêt à réessayer ; illimité par défaut, comme Temporal. */
    public RetryLimit $retryLimit;

    /** Délai avant la première retentative après un échec. */
    public Duration $initialInterval;

    /**
     * Plafond du délai entre deux retentatives. Null applique le défaut Temporal,
     * {@see DEFAULT_MAXIMUM_INTERVAL_FACTOR} × l'intervalle initial — indispensable dès lors que
     * les tentatives sont illimitées, sans quoi le backoff exponentiel diverge.
     */
    public ?Duration $maximumInterval;

    /** Les bornes temporelles de l'activité, prises ensemble. */
    public ActivityTimeouts $timeouts;

    /**
     * @param list<class-string<\Throwable>> $nonRetryableExceptions
     */
    public function __construct(
        ?RetryLimit $retryLimit = null,
        ?Duration $initialInterval = null,
        /** Coefficient d’exponential backoff entre retentatives. */
        public float $backoffCoefficient = 2.0,
        ?Duration $maximumInterval = null,
        /** Exceptions qui ne déclenchent pas de retry (class-string[]). */
        public array $nonRetryableExceptions = [],
        /** File d’attente cible (routage applicatif ; non utilisée par tous les transports). */
        public ?string $taskQueue = null,
        /** ID métier d’activité (sinon UUID). */
        public ?string $activityId = null,
        ?ActivityTimeouts $timeouts = null,
        public ActivityCancellationType $cancellationType = ActivityCancellationType::TryCancel,
        /** Résumé affichage UI (champ « summary » côté Temporal). */
        public ?string $summary = null,
    ) {
        $this->retryLimit = $retryLimit ?? RetryLimit::unlimited();
        $this->initialInterval = $initialInterval ?? Duration::seconds(1.0);
        $this->maximumInterval = $maximumInterval;
        $this->timeouts = $timeouts ?? ActivityTimeouts::none();
    }

    public static function default(): self
    {
        return new self();
    }

    /**
     * Délai à appliquer **avant** la tentative n° {@code $nextAttempt} (1-based), après l’échec
     * de la tentative précédente. Nul pour la première tentative.
     */
    public function retryDelayBeforeAttempt(int $nextAttempt): Duration
    {
        if ($nextAttempt <= 1) {
            return Duration::zero();
        }

        return $this->initialInterval
            ->multipliedBy($this->backoffCoefficient ** ($nextAttempt - 2))
            ->shortest($this->effectiveMaximumInterval());
    }

    /**
     * Plafond d'intervalle réellement appliqué, défaut Temporal compris.
     */
    public function effectiveMaximumInterval(): Duration
    {
        return $this->maximumInterval ?? $this->initialInterval->multipliedBy(self::DEFAULT_MAXIMUM_INTERVAL_FACTOR);
    }

    public function withRetryLimit(RetryLimit $retryLimit): self
    {
        return new self(
            $retryLimit,
            $this->initialInterval,
            $this->backoffCoefficient,
            $this->maximumInterval,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $this->activityId,
            $this->timeouts,
            $this->cancellationType,
            $this->summary,
        );
    }

    public function withTimeouts(ActivityTimeouts $timeouts): self
    {
        return new self(
            $this->retryLimit,
            $this->initialInterval,
            $this->backoffCoefficient,
            $this->maximumInterval,
            $this->nonRetryableExceptions,
            $this->taskQueue,
            $this->activityId,
            $timeouts,
            $this->cancellationType,
            $this->summary,
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

    /**
     * @return array{activity_options: array<string, mixed>}
     */
    public function toMetadata(): array
    {
        $activityOptions = [
            'max_attempts' => $this->retryLimit->toWireValue(),
            'initial_interval_seconds' => $this->initialInterval->toSeconds(),
            'backoff_coefficient' => $this->backoffCoefficient,
            'non_retryable_exceptions' => $this->nonRetryableExceptions,
            'cancellation_type' => $this->cancellationType->value,
        ];
        if (null !== $this->maximumInterval) {
            $activityOptions['maximum_interval_seconds'] = $this->maximumInterval->toSeconds();
        }
        if (null !== $this->taskQueue && '' !== $this->taskQueue) {
            $activityOptions['task_queue'] = $this->taskQueue;
        }
        if (null !== $this->activityId && '' !== $this->activityId) {
            $activityOptions['activity_id'] = $this->activityId;
        }
        $activityOptions += $this->timeouts->toMetadata();
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
            $cancellation = ActivityCancellationType::tryFrom((int) $opts['cancellation_type']) ?? ActivityCancellationType::TryCancel;
        }

        return new self(
            RetryLimit::fromWireValue((int) ($opts['max_attempts'] ?? 0)),
            Duration::seconds((float) ($opts['initial_interval_seconds'] ?? 1.0)),
            (float) ($opts['backoff_coefficient'] ?? 2.0),
            Duration::fromWireValue($opts['maximum_interval_seconds'] ?? null),
            \is_array($opts['non_retryable_exceptions'] ?? null) ? $opts['non_retryable_exceptions'] : [],
            isset($opts['task_queue']) ? (string) $opts['task_queue'] : null,
            isset($opts['activity_id']) ? (string) $opts['activity_id'] : null,
            ActivityTimeouts::fromMetadata($opts),
            $cancellation,
            isset($opts['summary']) ? (string) $opts['summary'] : null,
        );
    }
}
