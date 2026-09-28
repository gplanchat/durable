<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port\History;

/**
 * A child workflow slot's recorded outcome: which child ran there, and its result or the failure
 * that ended it (`result` is then null) (#325).
 */
final readonly class ChildWorkflowOutcome
{
    public function __construct(
        public string $childExecutionId,
        public mixed $result,
        public ?\Throwable $failed = null,
    ) {}
}
