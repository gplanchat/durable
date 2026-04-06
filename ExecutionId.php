<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Uuid\NativeUuidV7Generator;

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
        return new self((new NativeUuidV7Generator())->generate());
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
