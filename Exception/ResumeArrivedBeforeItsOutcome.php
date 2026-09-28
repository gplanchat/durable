<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

use Gplanchat\Durable\Transport\AwaitedFact;

/**
 * A resume arrived before the fact it announces was journalled (DUR050, DUR052).
 *
 * The activity worker sends the resume first and appends second, so this is the expected race, not
 * a fault. The handler concludes nothing; the transport's retry is the wait. A resume that runs out
 * of retries loses nothing: the worker sends another one after the append.
 */
final class ResumeArrivedBeforeItsOutcome extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        public readonly string $executionId,
        public readonly AwaitedFact $awaited,
    ) {
        parent::__construct(\sprintf(
            'The resume of execution "%s" arrived before the %s it announces; it waits for it.',
            $executionId,
            $awaited->describe(),
        ));
    }
}
