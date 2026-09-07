<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Duration;

/**
 * An activity attempt in transit towards a worker.
 *
 * The options cross the transport exactly as the caller built them; the transport bookkeeping —
 * attempt number, first queueing, delay before retrying — each has a field of its own rather than
 * a key in an opaque array.
 *
 * {@see toWireMetadata()} and {@see fromWireMetadata()} give transports the flat shape they need
 * in order to serialize. It has not changed.
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
        /** Attempt number, 1-based. */
        public int $attempt = 1,
        /** Instant of first queueing, timestamped by the backend that scheduled the activity. */
        public ?float $firstQueuedAt = null,
        /**
         * Delay to observe before retrying. Consumed by the transport, which translates it into
         * its own deferral mechanism, then forgets it — it does not survive the queueing.
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
     * Next attempt, to be retried after the given delay.
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
     * The delay once the transport has taken charge of it.
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
     * Flat shape expected by the journal and by the Temporal activity input.
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
