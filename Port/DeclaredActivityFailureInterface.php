<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Business exception raised by an activity, designed to be persisted in the event store
 * and rebuilt at the {@see \Gplanchat\Durable\WorkflowEnvironment::await()} call (deterministic replay).
 *
 * The payload returned by {@see self::toActivityFailureContext()} must be entirely
 * JSON-serialisable (no resources, objects, closures).
 */
interface DeclaredActivityFailureInterface extends \Throwable
{
    /**
     * @return array<string, mixed>
     */
    public function toActivityFailureContext(): array;

    /**
     * @param array<string, mixed> $context Values taken from the history (persisted payload)
     */
    public static function restoreFromActivityFailureContext(array $context): static;
}
