<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

use Gplanchat\Durable\Duration;

/**
 * The timeout given to a wait for a result elapsed. The execution keeps running.
 */
final class WorkflowResultTimeout extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly string $executionId,
        public readonly Duration $timeout,
        ?\Throwable $previous = null,
    ) {
        parent::__construct('The workflow execution did not finish within the timeout given to the wait.', 0, $previous);
    }
}
