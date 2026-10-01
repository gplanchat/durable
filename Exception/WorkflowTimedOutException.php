<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The server closed the execution when its execution or run timeout elapsed.
 */
final class WorkflowTimedOutException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly string $executionId,
    ) {
        parent::__construct(\sprintf('Workflow "%s" timed out on the Temporal side.', $executionId));
    }
}
