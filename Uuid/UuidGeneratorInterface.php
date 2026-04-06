<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Uuid;

/**
 * Port: UUID generation for the durable core.
 *
 * Concrete implementations:
 * - {@see NativeUuidV7Generator} – pure-PHP RFC 9562 UUID v7 (no framework dependency)
 */
interface UuidGeneratorInterface
{
    /**
     * Returns a new unique identifier string (UUID v7 or equivalent monotonic UUID).
     */
    public function generate(): string;
}
