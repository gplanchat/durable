<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\Failure\ActivityRetryState;

/**
 * Worker-side marker: **one** activity attempt failed, before the retry decision.
 *
 * Completes the trio {@see ActivityTaskStarted} / {@see ActivityTaskCompleted}: without this
 * event, the error of an attempt followed by a success disappears from the journal entirely.
 *
 * **Not terminal**: does not enter {@see \Gplanchat\Durable\Store\ActivityEventJournal::hasTerminalOutcomeForActivity()}.
 * The definitive outcome remains {@see ActivityCompleted} / {@see ActivityFailed} / {@see ActivityCancelled}.
 */
final readonly class ActivityTaskFailed implements Event
{
    public function __construct(
        private string $executionId,
        private string $activityId,
        private string $activityName,
        private int $attempt,
        private string $failureClass,
        private string $failureMessage,
        /** Next attempt scheduled ({@see ActivityRetryState::InProgress}) or reason for stopping. */
        private ActivityRetryState $retryState = ActivityRetryState::InProgress,
    ) {}

    public static function forThrowable(
        string $executionId,
        string $activityId,
        string $activityName,
        int $attempt,
        \Throwable $e,
        ActivityRetryState $retryState,
    ): self {
        $message = $e->getMessage();
        if (\strlen($message) > 2048) {
            $message = substr($message, 0, 2048) . '…';
        }

        return new self($executionId, $activityId, $activityName, $attempt, $e::class, $message, $retryState);
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function activityId(): string
    {
        return $this->activityId;
    }

    public function activityName(): string
    {
        return $this->activityName;
    }

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function failureClass(): string
    {
        return $this->failureClass;
    }

    public function failureMessage(): string
    {
        return $this->failureMessage;
    }

    public function retryState(): ActivityRetryState
    {
        return $this->retryState;
    }

    public function willRetry(): bool
    {
        return ActivityRetryState::InProgress === $this->retryState;
    }

    public function payload(): array
    {
        return [
            'activityId' => $this->activityId,
            'activityName' => $this->activityName,
            'attempt' => $this->attempt,
            'failureClass' => $this->failureClass,
            'failureMessage' => $this->failureMessage,
            'retryState' => $this->retryState->value,
        ];
    }
}
