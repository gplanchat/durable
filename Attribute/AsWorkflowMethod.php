<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class AsWorkflowMethod
{
    public function __construct(
        public readonly ?string $name = null,
    ) {}
}
