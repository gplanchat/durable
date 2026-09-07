<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The workflow did not catch an error coming from an activity (or a catastrophic error):
 * the integration must treat this as a bug in the workflow's algorithm / robustness.
 */
final class DurableWorkflowAlgorithmFailureException extends \RuntimeException
{
    public function __construct(
        string $message,
        int $code = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
