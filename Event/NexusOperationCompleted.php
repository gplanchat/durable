<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Une opération Nexus a abouti.
 *
 * L'`eventId` de la planification est ce qui la rattache à son opération : sans lui, le profileur
 * n'aurait que des événements flottants, impossibles à recomposer en une ligne de vie.
 */
final readonly class NexusOperationCompleted implements Event
{
    public function __construct(
        private string $executionId,
        private int $scheduledEventId,
    ) {}

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function scheduledEventId(): int
    {
        return $this->scheduledEventId;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return ['scheduledEventId' => $this->scheduledEventId];
    }
}
