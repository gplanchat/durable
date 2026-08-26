<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Duration;

/**
 * Une tentative d'activité en transit vers un worker.
 *
 * Les options franchissent le transport telles que l'appelant les a construites ; la comptabilité
 * de transport — numéro de tentative, première mise en file, délai avant reprise — a chacune son
 * champ plutôt qu'une clé dans un tableau opaque.
 *
 * {@see toWireMetadata()} et {@see fromWireMetadata()} donnent aux transports la forme plate dont
 * ils ont besoin pour sérialiser. Elle n'a pas changé.
 */
final readonly class ActivityMessage
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $executionId,
        public string $activityId,
        public string $activityName,
        public array $payload,
        public ?ActivityOptions $options = null,
        /** Numéro de la tentative, 1-based. */
        public int $attempt = 1,
        /** Instant de première mise en file, horodaté par le backend qui a planifié l'activité. */
        public ?float $firstQueuedAt = null,
        /**
         * Délai à respecter avant de reprendre. Consommé par le transport, qui le traduit dans
         * son propre mécanisme de report, puis l'oublie — il ne survit pas à la mise en file.
         */
        public ?Duration $retryDelay = null,
    ) {}

    public function withAttempt(int $attempt): self
    {
        return new self(
            $this->executionId,
            $this->activityId,
            $this->activityName,
            $this->payload,
            $this->options,
            $attempt,
            $this->firstQueuedAt,
            $this->retryDelay,
        );
    }

    /**
     * Prochaine tentative, à reprendre après le délai donné.
     */
    public function retryingIn(?Duration $delay): self
    {
        return new self(
            $this->executionId,
            $this->activityId,
            $this->activityName,
            $this->payload,
            $this->options,
            $this->attempt + 1,
            $this->firstQueuedAt,
            $delay,
        );
    }

    /**
     * Le délai une fois pris en charge par le transport.
     */
    public function withoutRetryDelay(): self
    {
        return new self(
            $this->executionId,
            $this->activityId,
            $this->activityName,
            $this->payload,
            $this->options,
            $this->attempt,
            $this->firstQueuedAt,
            null,
        );
    }

    /**
     * Forme plate attendue par le journal et par l'entrée d'activité Temporal.
     *
     * @return array<string, mixed>
     */
    public function toWireMetadata(): array
    {
        $metadata = $this->options?->toMetadata() ?? [];
        if (null !== $this->firstQueuedAt) {
            $metadata['queued_at'] = $this->firstQueuedAt;
            $metadata['first_queued_at'] = $this->firstQueuedAt;
        }
        $metadata['attempt'] = $this->attempt;
        if (null !== $this->retryDelay) {
            $metadata['retry_delay_seconds'] = $this->retryDelay->toSeconds();
        }

        return $metadata;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $metadata
     */
    public static function fromWireMetadata(
        string $executionId,
        string $activityId,
        string $activityName,
        array $payload,
        array $metadata,
    ): self {
        return new self(
            $executionId,
            $activityId,
            $activityName,
            $payload,
            ActivityOptions::fromMetadata($metadata),
            isset($metadata['attempt']) ? (int) $metadata['attempt'] : 1,
            isset($metadata['first_queued_at']) ? (float) $metadata['first_queued_at'] : null,
            Duration::fromWireValue($metadata['retry_delay_seconds'] ?? null),
        );
    }
}
