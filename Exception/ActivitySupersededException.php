<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The activity was cancelled because another concurrent branch (e.g. any/race) won.
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
