<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A child workflow cannot start under an id that is already in the event store, because its reuse
 * policy is `RejectDuplicate`. Start the child under a new id, or choose a policy that allows reuse.
 */
final class ChildWorkflowIdAlreadyUsedException extends \InvalidArgumentException implements ExceptionInterface
{
    private function __construct(public readonly string $childExecutionId)
    {
        parent::__construct('The child workflow id is already used in the event store, and its reuse policy rejects duplicates.');
    }

    public static function forId(string $childExecutionId): self
    {
        return new self($childExecutionId);
    }
}
