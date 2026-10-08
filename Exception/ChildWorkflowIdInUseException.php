<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A child workflow cannot start under an id whose run has not finished. No reuse policy allows it:
 * a policy decides about a finished run only.
 */
final class ChildWorkflowIdInUseException extends \InvalidArgumentException implements ExceptionInterface
{
    public static function forId(string $childExecutionId): self
    {
        return new self(\sprintf('Child workflow execution id %s is still running; no reuse policy allows starting another run under it.', $childExecutionId));
    }
}
