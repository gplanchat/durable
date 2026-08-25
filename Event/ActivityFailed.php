<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\Failure\FailureEnvelope;

final readonly class ActivityFailed implements Event
{
    /**
     * @param array<string, mixed>                                   $failureContext
     * @param list<array{class: string, message: string, code: int}> $failurePrevious
     */
    public function __construct(
        private string $executionId,
        private string $activityId,
        private string $failureClass,
        private string $failureMessage,
        private int $failureCode = 0,
        private array $failureContext = [],
        private string $failureTrace = '',
        private array $failurePrevious = [],
        private string $activityName = '',
        private int $failureAttempt = 0,
        /** Pourquoi les tentatives se sont arrêtées ; null = journal antérieur au discriminant. */
        private ?ActivityRetryState $retryState = null,
    ) {
    }

    public static function fromEnvelope(
        string $executionId,
        string $activityId,
        FailureEnvelope $envelope,
        string $activityName = '',
        int $attempt = 0,
        ?ActivityRetryState $retryState = null,
    ): self {
        return new self(
            $executionId,
            $activityId,
            $envelope->class,
            $envelope->message,
            $envelope->code,
            $envelope->context,
            (string) ($envelope->trace ?? ''),
            $envelope->previousChain,
            $activityName,
            $attempt,
            $retryState,
        );
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

    public function failureAttempt(): int
    {
        return $this->failureAttempt;
    }

    public function retryState(): ?ActivityRetryState
    {
        return $this->retryState;
    }

    /**
     * Vrai lorsque l'échec est dû à l'épuisement des retentatives (« ActivityStalled »).
     */
    public function isStalled(): bool
    {
        return ActivityRetryState::MaximumAttemptsReached === $this->retryState;
    }

    public function failureClass(): string
    {
        return $this->failureClass;
    }

    public function failureMessage(): string
    {
        return $this->failureMessage;
    }

    public function failureCode(): int
    {
        return $this->failureCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function failureContext(): array
    {
        return $this->failureContext;
    }

    public function failureTrace(): string
    {
        return $this->failureTrace;
    }

    /**
     * @return list<array{class: string, message: string, code: int}>
     */
    public function failurePrevious(): array
    {
        return $this->failurePrevious;
    }

    public function payload(): array
    {
        return [
            'activityId' => $this->activityId,
            'activityName' => $this->activityName,
            'failureAttempt' => $this->failureAttempt,
            'failureClass' => $this->failureClass,
            'failureMessage' => $this->failureMessage,
            'failureCode' => $this->failureCode,
            'failureContext' => $this->failureContext,
            'failureTrace' => $this->failureTrace,
            'failurePrevious' => $this->failurePrevious,
            'retryState' => $this->retryState?->value,
        ];
    }
}
