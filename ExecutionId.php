<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Symfony\Component\Uid\Uuid;

/**
 * Identifiant d'exécution de workflow (value object).
 */
final readonly class ExecutionId implements \Stringable
{
    private function __construct(
        private string $value,
    ) {
    }

    public static function fromString(string $value): self
    {
        return new self($value);
    }

    public static function generate(): self
    {
        return new self((string) Uuid::v7());
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
