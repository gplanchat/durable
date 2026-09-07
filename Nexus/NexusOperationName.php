<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus;

/**
 * The name of the operation called on the Nexus service.
 *
 * **This object is stricter than the server, and that is deliberate** — like
 * {@see \Gplanchat\Durable\TaskQueue}, and the opposite of {@see NexusEndpoint}.
 *
 * Probed on Temporal 1.31.2 (task 1.1): the server validates **nothing** here. Empty, a space,
 * whitespace at the edges, an inner tab, a control character, a thousand characters — everything is
 * accepted, and `NEXUS_OPERATION_SCHEDULED` records the name verbatim. Nothing follows: the
 * operation stays scheduled, waiting for a handler whose name will never match, without a single
 * line of error. That is exactly the silent failure of a misnamed task queue, and only a rule
 * stricter than the server can prevent it.
 *
 * What is refused is limited to what can only be a mistake: an empty or entirely blank name,
 * whitespace at the edges, a control character. **No length bound and no alphabet are imposed** —
 * the server showed none, and task 1.4 forbids writing an invariant that has not been observed. A
 * dot, a slash or a capital letter are therefore legitimate names.
 *
 * As everywhere else, the typo that remains a plausible name is not caught: only a registry of the
 * operations actually served could do that.
 */
final readonly class NexusOperationName
{
    private function __construct(
        private string $name,
    ) {}

    public static function named(string $name): self
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('A Nexus operation name is not set.');
        }
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('A Nexus operation name cannot be blank: the server would accept it and no handler would ever match it.');
        }
        if ($name !== trim($name)) {
            throw new \InvalidArgumentException(\sprintf(
                'A Nexus operation name "%s" has leading or trailing whitespace. The server records it as given, so a handler registered under the trimmed name would never be matched.',
                $name,
            ));
        }
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new \InvalidArgumentException(\sprintf(
                'A Nexus operation name "%s" contains a control character; such names are invisible in logs and impossible to match by eye.',
                addcslashes($name, "\0..\37\177"),
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
