<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

#[\Attribute(\Attribute::TARGET_CLASS)]
final class Activity
{
    public function __construct(
        public readonly string $name,
    ) {
    }
}
