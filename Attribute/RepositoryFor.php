<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

/**
 * On a constructor parameter typed {@see \Gplanchat\Durable\WorkflowRepository}: asks the host for
 * the repository of that workflow class, without declaring a repository class. A plain attribute
 * with no framework dependency; each host resolves it its own way.
 */
#[\Attribute(\Attribute::TARGET_PARAMETER)]
final readonly class RepositoryFor
{
    /**
     * @param class-string $workflow
     */
    public function __construct(
        public string $workflow,
    ) {}
}
