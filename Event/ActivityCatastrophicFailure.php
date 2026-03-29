<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Échec d'activité non journalisable de façon sûre (payload JSON impossible) :
 * considéré comme défaillance grave du code / des données de l'activité.
 */
final readonly class ActivityCatastrophicFailure implements Event
{
    private function __construct(
        private string $executionId,
        private string $activityId,
        private string $activityName,
        private int $attempt,
        private string $exceptionClass,
        private string $exceptionMessage,
        private string $reasonCode,
    ) {
    }

    /**
     * @param array<string, mixed> $p
     */
    public static function fromStoredPayload(string $executionId, array $p): self
    {
        return new self(
            $executionId,
            (string) $p['activityId'],
            (string) $p['activityName'],
            (int) $p['attempt'],
            (string) $p['exceptionClass'],
            (string) $p['exceptionMessage'],
            (string) $p['reasonCode'],
        );
    }

    public static function forThrowable(
        string $executionId,
        string $activityId,
        string $activityName,
        int $attempt,
        \Throwable $e,
        string $reasonCode,
    ): self {
        $message = $e->getMessage();
        if (\strlen($message) > 2048) {
            $message = substr($message, 0, 2048).'…';
        }

        return new self(
            $executionId,
            $activityId,
            $activityName,
            $attempt,
            $e::class,
            $message,
            $reasonCode,
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

    public function attempt(): int
    {
        return $this->attempt;
    }

    public function exceptionClass(): string
    {
        return $this->exceptionClass;
    }

    public function exceptionMessage(): string
    {
        return $this->exceptionMessage;
    }

    public function reasonCode(): string
    {
        return $this->reasonCode;
    }

    public function payload(): array
    {
        return [
            'activityId' => $this->activityId,
            'activityName' => $this->activityName,
            'attempt' => $this->attempt,
            'exceptionClass' => $this->exceptionClass,
            'exceptionMessage' => $this->exceptionMessage,
            'reasonCode' => $this->reasonCode,
        ];
    }
}
