<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class AsSignalMethod
{
    public function __construct(
        public readonly \BackedEnum|string $name,
    ) {}

    /** Le nom tel qu'il voyage : sur le fil, un signal est une chaîne. */
    public function signalName(): string
    {
        return $this->name instanceof \BackedEnum ? (string) $this->name->value : $this->name;
    }
}
