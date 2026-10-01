<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The workflow failed, and its own exception cannot be rebuilt where the caller waits: the class
 * does not load in this process, its constructor does not take `(message, code)`, or the failure
 * carries no Durable details (an older history, a worker that is not Durable).
 *
 * The message carries the failure message the server recorded.
 */
final class WorkflowFailedException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly string $executionId,
        string $failureMessage,
    ) {
        parent::__construct(\sprintf('Workflow "%s" failed: %s', $executionId, $failureMessage));
    }
}
