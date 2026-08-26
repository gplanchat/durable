<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\Failure\ActivityRetryState;

/**
 * Worker-side marker: **une** tentative d'activité a échoué, avant la décision de retry.
 *
 * Complète le trio {@see ActivityTaskStarted} / {@see ActivityTaskCompleted} : sans cet événement,
 * l'erreur d'une tentative suivie d'un succès disparaît complètement du journal.
 *
 * **Non terminal** : n'entre pas dans {@see \Gplanchat\Durable\Store\ActivityEventJournal::hasTerminalOutcomeForActivity()}.
 * L'issue définitive reste {@see ActivityCompleted} / {@see ActivityFailed} / {@see ActivityCancelled}.
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
        /** Prochaine tentative planifiée ({@see ActivityRetryState::InProgress}) ou raison de l'arrêt. */
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
