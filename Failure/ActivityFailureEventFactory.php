<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Failure;

use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Port\DeclaredActivityFailureInterface;

/**
 * Construit un événement d'échec d'activité persistable ou un événement catastrophique
 * lorsque l'échec ne peut pas être sérialisé de façon sûre pour le journal.
 */
final class ActivityFailureEventFactory
{
    public static function fromActivityThrowable(
        string $executionId,
        string $activityId,
        string $activityName,
        int $attempt,
        \Throwable $e,
    ): ActivityFailed|ActivityCatastrophicFailure {
        if ($e instanceof DeclaredActivityFailureInterface) {
            try {
                json_encode($e->toActivityFailureContext(), \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
            } catch (\JsonException) {
                return ActivityCatastrophicFailure::forThrowable(
                    $executionId,
                    $activityId,
                    $activityName,
                    $attempt,
                    $e,
                    'declared_activity_failure_context_not_json_serializable',
                );
            }
        }

        $envelope = FailureEnvelope::fromThrowable($e);
        $failed = ActivityFailed::fromEnvelope(
            $executionId,
            $activityId,
            $envelope,
            $activityName,
            $attempt,
        );

        if (!self::isPayloadJsonSerializable($failed)) {
            return ActivityCatastrophicFailure::forThrowable(
                $executionId,
                $activityId,
                $activityName,
                $attempt,
                $e,
                'activity_failed_payload_not_json_serializable',
            );
        }

        return $failed;
    }

    private static function isPayloadJsonSerializable(ActivityFailed $failed): bool
    {
        try {
            json_encode($failed->payload(), \JSON_THROW_ON_ERROR | \JSON_INVALID_UTF8_SUBSTITUTE);
        } catch (\JsonException) {
            return false;
        }

        return true;
    }
}
