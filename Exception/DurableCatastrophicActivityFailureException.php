<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

use Gplanchat\Durable\Event\ActivityCatastrophicFailure;

/**
 * Activité en erreur dont la représentation n'a pas pu être persistée dans l'event store.
 */
final class DurableCatastrophicActivityFailureException extends \RuntimeException
{
    public function __construct(
        private readonly ActivityCatastrophicFailure $event,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf(
                '[%s] Catastrophic activity failure (%s): %s — %s (attempt %d, reason=%s)',
                $event->activityId(),
                $event->activityName(),
                $event->exceptionClass(),
                $event->exceptionMessage(),
                $event->attempt(),
                $event->reasonCode(),
            ),
            0,
            $previous,
        );
    }

    public function event(): ActivityCatastrophicFailure
    {
        return $this->event;
    }

    public function activityId(): string
    {
        return $this->event->activityId();
    }

    public function activityName(): string
    {
        return $this->event->activityName();
    }

    public function attempt(): int
    {
        return $this->event->attempt();
    }
}
