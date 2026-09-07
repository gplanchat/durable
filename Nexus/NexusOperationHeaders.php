<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

/**
 * The headers carried through to the handler of a Nexus operation.
 *
 * **This object is not stricter than the server**, save on one point, and the gap is measured.
 * Probed on Temporal 1.31.2, the server accepts as they are an empty key, an empty value,
 * whitespace at the edges, a line break, a space inside the key, a thousand characters. Refusing
 * all of that would reject headers it carries without blinking — the opposite mistake to the one
 * {@see \Gplanchat\Durable\TaskQueue} avoids.
 *
 * One single thing escapes it, and it is silent: **it lowercases keys**. Two keys that differ only
 * by case therefore collide — two headers go in, one comes out, with no error and nothing in the
 * history to say which one was dropped.
 *
 * Hence the only two rules here, both drawn from that observation:
 *
 * - **the key is lowercased at construction**, so that what the caller holds is what the server
 *   will keep. It is a coercion, not a refusal: `X-Correlation` is a perfectly valid header, it
 *   simply *is* `x-correlation`;
 * - **a collision is refused**, because there the caller is asking for something the server does
 *   not know how to do and will not say.
 */
final readonly class NexusOperationHeaders
{
    /** @param array<string, string> $headers already lowercased and free of collisions */
    private function __construct(
        private array $headers,
    ) {}

    /**
     * @param array<string, string> $headers
     */
    public static function of(array $headers): self
    {
        $lowered = [];
        $origins = [];
        foreach ($headers as $key => $value) {
            $lowerKey = strtolower((string) $key);
            if (isset($origins[$lowerKey])) {
                throw new \InvalidArgumentException(\sprintf(
                    'Nexus headers "%s" and "%s" collide on "%s": the server lowercases keys, so only '
                    . 'one of the two would survive — and it would not say which.',
                    $origins[$lowerKey],
                    (string) $key,
                    $lowerKey,
                ));
            }

            $origins[$lowerKey] = (string) $key;
            $lowered[$lowerKey] = $value;
        }

        return new self($lowered);
    }

    public static function none(): self
    {
        return new self([]);
    }

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return $this->headers;
    }

    public function isEmpty(): bool
    {
        return [] === $this->headers;
    }
}
