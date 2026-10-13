<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The workflow of this execution declares no `#[AsQueryMethod]` under this name.
 */
final class WorkflowQueryNotFound extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $queryName,
    ) {
        parent::__construct('The workflow declares no query under this name.');
    }
}
