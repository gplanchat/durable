<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

/**
 * The name of a Nexus endpoint: where an operation is routed.
 *
 * **This object is not stricter than the server, and that is deliberate** — the opposite of the
 * choice made for {@see \Gplanchat\Durable\TaskQueue}. A misnamed queue is accepted by the server
 * and then never served: the work sleeps there, nothing shows up in the logs, and only a rule
 * stricter than the server can prevent it. A misnamed endpoint has no such silent failure: the
 * server flatly refuses it at creation. Inventing an extra rule here would therefore prevent no
 * mistake — it would only reject perfectly valid names.
 *
 * The rule is the one the server states itself, observed on Temporal 1.31.2 (task 1.1) and pinned
 * by `NexusEndpointNameRulesTest`: `^[a-zA-Z][a-zA-Z0-9\-]*[a-zA-Z0-9]$`, 200 characters. One
 * consequence of that pattern is surprising and deserves saying: it requires a first **and** a last
 * character, so a single letter (`a`) is refused.
 *
 * The only distinction kept is the server's: an empty name is not *malformed*, it is *absent*, and
 * the two deserve different messages.
 */
final readonly class NexusEndpoint
{
    /** The server's limit, probed: 200 accepted, 201 refused. */
    public const MAX_LENGTH = 200;

    /** The pattern the server states in its own refusal message. */
    private const PATTERN = '/^[a-zA-Z][a-zA-Z0-9\-]*[a-zA-Z0-9]$/';

    private function __construct(
        private string $name,
    ) {}

    public static function named(string $name): self
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('A Nexus endpoint name is not set.');
        }
        if (\strlen($name) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'A Nexus endpoint name is at most %d characters, %d given.',
                self::MAX_LENGTH,
                \strlen($name),
            ));
        }
        if (1 !== preg_match(self::PATTERN, $name)) {
            throw new \InvalidArgumentException(\sprintf(
                'Nexus endpoint name "%s" does not match %s: it starts with a letter, continues with '
                . 'letters, digits or hyphens, and ends with a letter or a digit — which also means a '
                . 'single character is too short.',
                addcslashes($name, "\0..\37\177"),
                '^[a-zA-Z][a-zA-Z0-9\-]*[a-zA-Z0-9]$',
            ));
        }

        return new self($name);
    }

    /**
     * Boundary coercion: accepts whatever the caller has at hand.
     */
    public static function from(self|string $value): self
    {
        return $value instanceof self ? $value : self::named($value);
    }

    /**
     * From a configuration value that may be absent.
     */
    public static function fromNullable(self|string|null $value): ?self
    {
        return null === $value || '' === $value ? null : self::from($value);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
