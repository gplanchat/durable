<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * L'activité a été annulée car une autre branche concurrente (ex. any/race) a gagné.
 */
final class ActivitySupersededException extends \RuntimeException
{
    public function __construct(
        private readonly string $activityId,
        private readonly string $cancellationReason,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('Activity %s was superseded (%s)', $activityId, $cancellationReason),
            0,
            $previous,
        );
    }

    public function activityId(): string
    {
        return $this->activityId;
    }

    public function cancellationReason(): string
    {
        return $this->cancellationReason;
    }
}
