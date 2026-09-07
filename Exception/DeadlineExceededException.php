<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

use Gplanchat\Durable\Duration;

/**
 * The deadline passed to {@see \Gplanchat\Durable\WorkflowEnvironment::await()} or to
 * {@see \Gplanchat\Durable\WorkflowEnvironment::await()} elapsed before the awaited work
 * settled.
 *
 * A failure, not a value: `null` is an answer a bounded piece of work is entitled to return, and
 * the point of this deadline is precisely to tell it apart from that (ADR DUR032).
 *
 * Not to be confused with {@see \Gplanchat\Durable\Activity\ActivityTimeouts}, which bounds an
 * activity attempt on the server side. This one bounds *this* wait, in *this* execution.
 */
final class DeadlineExceededException extends \RuntimeException
{
    public function __construct(
        private readonly Duration $deadline,
        private readonly string $awaited,
        ?\Throwable $previous = null,
    ) {
        parent::__construct(
            \sprintf('Deadline of %s elapsed while awaiting %s', $deadline, $awaited),
            0,
            $previous,
        );
    }

    public function deadline(): Duration
    {
        return $this->deadline;
    }

    /** What was awaited, as it can be named from the journal (activity, timer, signal). */
    public function awaited(): string
    {
        return $this->awaited;
    }
}
