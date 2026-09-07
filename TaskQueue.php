<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

/**
 * The name of a task queue: where the work is dropped off, and therefore where a worker has to
 * come and pick it up.
 *
 * The server requires almost nothing — not empty, a thousand characters at most. Probed, it
 * accepts `" "`, edge spaces, tabs and newlines. Yet a badly named queue produces no error at
 * all: the work is dropped there and nobody comes for it. The execution simply stays pending,
 * with nothing in the logs.
 *
 * So this object is **stricter than the server** about what can only be a mistake: whitespace
 * at the edges, an all-blank name, control characters. It does not catch the typo that stays a
 * valid name (`durable-activites` for `durable-activities`) — only a registry of the queues
 * actually served could.
 */
final readonly class TaskQueue
{
    /** The server's limit, probed: 1000 accepted, 1001 refused ("taskQueue length exceeds limit"). */
    public const MAX_LENGTH = 1000;

    private function __construct(
        private string $name,
    ) {}

    public static function named(string $name): self
    {
        if ('' === $name) {
            throw new \InvalidArgumentException('A task queue name cannot be empty.');
        }
        if ('' === trim($name)) {
            throw new \InvalidArgumentException('A task queue name cannot be blank: the server would accept it and no worker would ever find it.');
        }
        if ($name !== trim($name)) {
            throw new \InvalidArgumentException(\sprintf(
                'Task queue name "%s" has leading or trailing whitespace. The server keeps it, so a worker polling the trimmed name would never be matched.',
                $name,
            ));
        }
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $name)) {
            throw new \InvalidArgumentException(\sprintf(
                'Task queue name "%s" contains a control character; such names are invisible in logs and impossible to match by eye.',
                addcslashes($name, "\0..\37\177"),
            ));
        }
        if (\strlen($name) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(\sprintf(
                'A task queue name is at most %d bytes, %d given.',
                self::MAX_LENGTH,
                \strlen($name),
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
