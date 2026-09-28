<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

use Gplanchat\Durable\Store\PassFence;

/**
 * A newer pass has claimed this execution (DUR053): the older one may not write to its journal.
 * The handler that ran the pass acknowledges its message and stops; the newer pass owns the run.
 */
final class SupersededPassException extends \RuntimeException implements ExceptionInterface
{
    public static function for(PassFence $fence): self
    {
        return new self(\sprintf('Pass %d of execution "%s" has been superseded by a newer one.', $fence->epoch, $fence->executionId));
    }
}
