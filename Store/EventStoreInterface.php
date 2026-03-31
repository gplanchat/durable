<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Event\Event;

/**
 * Port de persistance des événements de workflow (event sourcing).
 *
 * @see ADR004 Ports et Adapters
 */
interface EventStoreInterface
{
    public function append(Event $event): void;

    /**
     * @return iterable<Event> événements de l’exécution identifiée uniquement, ordre d’insertion ; le store DBAL parcourt le résultat comme un curseur
     */
    public function readStream(string $executionId): iterable;

    /**
     * Même flux que {@see readStream} avec l’instant d’enregistrement côté store (profil type Temporal « Event time »).
     *
     * @return iterable<array{event: Event, recordedAt: \DateTimeImmutable|null}>
     */
    public function readStreamWithRecordedAt(string $executionId): iterable;

    /**
     * Nombre d’événements persistés pour cette exécution (équivalent à compter le flux {@see readStream} sans le matérialiser entièrement côté DBAL lorsque possible).
     */
    public function countEventsInStream(string $executionId): int;
}
