<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Uuid;

/**
 * Pure-PHP UUID v7 generator (RFC 9562) — no framework dependency.
 *
 * Layout (128 bits):
 *   [48-bit ms timestamp][4-bit ver=0x7][12-bit rand_a][2-bit variant=0b10][62-bit rand_b]
 */
final class NativeUuidV7Generator implements UuidGeneratorInterface
{
    public function generate(): string
    {
        $ms = (int) (microtime(true) * 1000);

        $bytes = random_bytes(10);

        $b = [
            ($ms >> 40) & 0xFF,
            ($ms >> 32) & 0xFF,
            ($ms >> 24) & 0xFF,
            ($ms >> 16) & 0xFF,
            ($ms >> 8) & 0xFF,
            $ms & 0xFF,
            0x70 | (ord($bytes[0]) & 0x0F),
            ord($bytes[1]),
            0x80 | (ord($bytes[2]) & 0x3F),
            ord($bytes[3]),
            ord($bytes[4]),
            ord($bytes[5]),
            ord($bytes[6]),
            ord($bytes[7]),
            ord($bytes[8]),
            ord($bytes[9]),
        ];

        return vsprintf('%02x%02x%02x%02x-%02x%02x-%02x%02x-%02x%02x-%02x%02x%02x%02x%02x%02x', $b);
    }
}
