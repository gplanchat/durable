<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A child workflow cannot start under an id whose earlier run completed successfully, because its
 * reuse policy is `AllowDuplicateFailedOnly`. Start the child under a new id, or choose
 * `AllowDuplicate`.
 */
final class ChildWorkflowIdCompletedException extends \InvalidArgumentException implements ExceptionInterface
{
    private function __construct(public readonly string $childExecutionId)
    {
        parent::__construct('The child workflow id belongs to a run that completed successfully, and its reuse policy allows reuse after a failure only.');
    }

    public static function forId(string $childExecutionId): self
    {
        return new self($childExecutionId);
    }
}
