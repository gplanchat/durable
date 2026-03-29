<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Exception métier émise par une activité, conçue pour être persistée dans l'event store
 * et reconstituée à l'appel {@see \Gplanchat\Durable\WorkflowEnvironment::await()} (replay déterministe).
 *
 * Le payload retourné par {@see self::toActivityFailureContext()} doit être entièrement
 * JSON-sérialisable (pas de ressources, objets, closures).
 */
interface DeclaredActivityFailureInterface extends \Throwable
{
    /**
     * @return array<string, mixed>
     */
    public function toActivityFailureContext(): array;

    /**
     * @param array<string, mixed> $context Valeurs issues de l'historique (payload persisté)
     */
    public static function restoreFromActivityFailureContext(array $context): static;
}
