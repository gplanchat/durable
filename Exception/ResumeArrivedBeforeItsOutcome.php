<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A resume arrived before the activity outcome it announces was journalled (DUR050).
 *
 * The activity worker sends the resume first and appends second, so this is the expected race, not
 * a fault. The handler concludes nothing; the transport's retry is the wait. A resume that runs out
 * of retries loses nothing: the worker sends another one after the append.
 */
final class ResumeArrivedBeforeItsOutcome extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly string $executionId,
        public readonly string $activityId,
    ) {
        parent::__construct(\sprintf(
            'The resume of execution "%s" arrived before the outcome of activity "%s"; it waits for it.',
            $executionId,
            $activityId,
        ));
    }
}
