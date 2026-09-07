<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

use Gplanchat\Durable\ContinueAsNewOptions;

/**
 * Thrown by {@see \Gplanchat\Durable\ExecutionContext::continueAsNew()} to end the current run
 * and chain a new run with a blank history (same business logic as Temporal continue-as-new).
 *
 * The engine appends {@see \Gplanchat\Durable\Event\WorkflowContinuedAsNew} then propagates this exception.
 */
final class ContinueAsNewRequested extends \RuntimeException
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public readonly string $workflowType,
        public readonly array $payload,
        public readonly ?ContinueAsNewOptions $options = null,
    ) {
        parent::__construct(\sprintf('Continue as new: workflow type %s', $workflowType));
    }
}
