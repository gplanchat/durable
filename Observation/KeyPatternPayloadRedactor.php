<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * Masks the value of every key matching a pattern, at any depth, and truncates long strings.
 *
 * A key-name heuristic: a secret stored under an innocent key goes through. It is the default,
 * not a guarantee; an application with a known payload shape implements the interface itself.
 */
final class KeyPatternPayloadRedactor implements PayloadRedactorInterface
{
    public const MASK = '***';

    public function __construct(
        private readonly string $keyPattern = '/password|secret|token|authorization|card/i',
        private readonly int $maxStringBytes = 1024,
    ) {}

    public function redact(mixed $payload): mixed
    {
        if (\is_string($payload) && \strlen($payload) > $this->maxStringBytes) {
            $cut = substr($payload, 0, $this->maxStringBytes);
            // A byte cut can split a UTF-8 character, and the command encodes with
            // JSON_THROW_ON_ERROR; give back the partial character's bytes (three at most).
            for ($i = 0; $i < 3 && 1 !== preg_match('//u', $cut); ++$i) {
                $cut = substr($cut, 0, -1);
            }

            return \sprintf('%s… (%d more bytes)', $cut, \strlen($payload) - \strlen($cut));
        }
        if (!\is_array($payload)) {
            return $payload;
        }

        foreach ($payload as $key => $value) {
            $payload[$key] = \is_string($key) && 1 === preg_match($this->keyPattern, $key)
                ? self::MASK
                : $this->redact($value);
        }

        return $payload;
    }
}
