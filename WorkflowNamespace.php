<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * The namespace: the isolation boundary inside which executions, queues and search attributes
 * live.
 *
 * Named `WorkflowNamespace` for want of anything better — `namespace` is a reserved word of the
 * language.
 *
 * Unlike {@see TaskQueue}, a mistake here does not go unnoticed: the server answers
 * `NOT_FOUND, Namespace "…" is not found`, a namespace having to exist before use. So what this
 * object mostly brings is **typing** — namespace and task queue are two neighbouring strings in
 * the same constructors, and swapping them only showed at run time.
 *
 * Probed: the server only requires "not empty". It accepts spaces, capitals, accents, tabs and
 * more than 255 characters. It is on the other hand **case sensitive**, and sensitive to
 * whitespace: `DURABLE-TEST` and `durable-test ` are namespaces distinct from `durable-test`,
 * and therefore not found.
 */
final readonly class WorkflowNamespace
{
    /** The server's system namespace; it hosts no application workflow. */
    public const SYSTEM = 'temporal-system';

    private function __construct(
        private string $name,
    ) {}

    public static function named(string $name): self
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('A namespace cannot be empty.');
        }
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('A namespace cannot be blank.');
        }
        if ($name !== trim($name)) {
            throw new \InvalidArgumentException(\sprintf(
                'Namespace "%s" has leading or trailing whitespace. The server compares names byte for byte, so it would report it as not found.',
                $name,
            ));
        }
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new \InvalidArgumentException(\sprintf(
                'Namespace "%s" contains a control character.',
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

    public static function fromNullable(self|string|null $value): ?self
    {
        return null === $value || '' === $value ? null : self::from($value);
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The server's system namespace, where no application workflow belongs.
     */
    public function isSystem(): bool
    {
        return self::SYSTEM === $this->name;
    }

    /**
     * Case-sensitive comparison, like the server's.
     */
    public function equals(self $other): bool
    {
        return $this->name === $other->name;
    }

    public function __toString(): string
    {
        return $this->name;
    }
}
