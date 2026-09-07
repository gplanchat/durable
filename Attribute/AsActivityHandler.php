<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

/**
 * Marks a class as the implementation of a contract's activities (interface + #[AsActivityMethod]).
 * The bundle registers each activity on {@see \Gplanchat\Durable\ActivityExecutor} at compile-time.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsActivityHandler
{
    /**
     * @param class-string $contract
     */
    public function __construct(
        public string $contract,
    ) {}
}
