<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

/**
 * A timestamp read back from a SQL column, as the DBAL and Illuminate stores write them: in UTC.
 * A column type without a zone gives a bare string back, read here as UTC; an explicit offset in the
 * string wins, and a date the driver already built is kept as is.
 */
final class StoredTimestamp
{
    public static function toDateTime(mixed $raw): ?\DateTimeImmutable
    {
        if ($raw instanceof \DateTimeImmutable) {
            return $raw;
        }
        if (!\is_string($raw) || '' === $raw) {
            return null;
        }

        return new \DateTimeImmutable($raw, new \DateTimeZone('UTC'));
    }
}
