<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Failure;

/**
 * Pourquoi une activité a cessé d'être retentée (discriminant porté par {@see \Gplanchat\Durable\Event\ActivityFailed}).
 *
 * Aligné sur {@see \Temporal\Api\Enums\V1\RetryState} : Temporal modélise cet état comme un **champ**
 * de `ActivityTaskFailedEventAttributes` / `ActivityFailureInfo`, pas comme un type d'événement distinct.
 */
enum ActivityRetryState: string
{
    /** Une nouvelle tentative est planifiée (porté par {@see \Gplanchat\Durable\Event\ActivityTaskFailed}). */
    case InProgress = 'in_progress';

    /** L'exception fait partie de {@see \Gplanchat\Durable\Activity\ActivityOptions::$nonRetryableExceptions}. */
    case NonRetryableFailure = 'non_retryable_failure';

    /** Timeout schedule-to-start / schedule-to-close : plus aucune tentative n'est autorisée. */
    case Timeout = 'timeout';

    /** Toutes les tentatives autorisées ont été consommées — « ActivityStalled ». */
    case MaximumAttemptsReached = 'maximum_attempts_reached';

    /** Aucune politique de retry active (maxAttempts = 0 et pas de défaut bundle) : échec dès la 1re tentative. */
    case RetryPolicyNotSet = 'retry_policy_not_set';

    /**
     * Le retry côté PHP est désactivé par le transport ({@see \Gplanchat\Durable\Transport\NoopActivityTransport},
     * worker Temporal natif) : l'échec journalisé est **synthétique**, la vraie cause est portée par Temporal.
     */
    case TransportRetryDisabled = 'transport_retry_disabled';

    /**
     * Vrai si l'événement décrit un arrêt définitif consécutif à une vraie défaillance métier
     * (par opposition à un marqueur d'infrastructure).
     */
    public function isTerminalBusinessFailure(): bool
    {
        return match ($this) {
            self::NonRetryableFailure, self::MaximumAttemptsReached, self::Timeout, self::RetryPolicyNotSet => true,
            self::InProgress, self::TransportRetryDisabled => false,
        };
    }
}
