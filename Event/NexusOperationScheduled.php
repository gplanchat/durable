<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Une opération Nexus a été planifiée.
 *
 * Porte le site d'appel — endpoint, service, opération — parce que c'est ce qu'on cherche en
 * ouvrant un profil : quel service externe cette exécution appelle, et lequel a coûté cher.
 *
 * L'identité est l'`eventId` de la planification. Ce n'est pas un choix : c'est par lui que
 * Temporal rattache les états terminaux à leur opération, et c'est donc la seule clé qui permette
 * de recomposer une ligne de vie.
 */
final readonly class NexusOperationScheduled implements Event
{
    public function __construct(
        private string $executionId,
        private int $scheduledEventId,
        private string $endpoint,
        private string $service,
        private string $operation,
    ) {}

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function scheduledEventId(): int
    {
        return $this->scheduledEventId;
    }

    public function endpoint(): string
    {
        return $this->endpoint;
    }

    public function service(): string
    {
        return $this->service;
    }

    public function operation(): string
    {
        return $this->operation;
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'scheduledEventId' => $this->scheduledEventId,
            'endpoint' => $this->endpoint,
            'service' => $this->service,
            'operation' => $this->operation,
        ];
    }
}
