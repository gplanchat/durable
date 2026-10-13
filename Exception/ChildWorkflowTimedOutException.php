<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * An inline child workflow had more waiting ahead of it than its execution timeout allows: the
 * runner stops it where the deadline falls, and the parent sees a "timed out" failure (#979).
 */
final class ChildWorkflowTimedOutException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly string $childExecutionId,
        public readonly float $dueAt,
    ) {
        parent::__construct('The child workflow outlived its execution timeout.');
    }
}
