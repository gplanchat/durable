<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Failure\FailureEnvelope;

/**
 * An update that is not in the journal yet, handed to the execution for the current pass.
 *
 * The task 1.3 probe showed it: an incoming update does not reach the worker through the
 * history. It arrives alongside, on the task, and the worker accepts it *and* answers it on that
 * same task. So this is for the first pass only — acceptance writes the request into the
 * history, and from the next replay onwards the update is positioned like any other signal.
 *
 * The outcome is deposited here rather than journalled by the execution: it is the caller of the
 * pass that answers, the way the worker returns its `Response` to the server, and that records.
 */
final class PendingUpdate
{
    public bool $handled = false;

    public mixed $result = null;

    public ?FailureEnvelope $failure = null;

    /**
     * @param array<string, mixed> $arguments
     */
    public function __construct(
        public readonly string $name,
        public readonly array $arguments = [],
    ) {}
}
