<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

/**
 * Marks a subclass of {@see \Gplanchat\Durable\WorkflowRepository} as the repository of one
 * workflow class.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsWorkflowRepository
{
    /**
     * @param class-string $workflow
     */
    public function __construct(
        public string $workflow,
    ) {}
}
