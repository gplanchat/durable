<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * A read-only pass was asked of an execution runtime that runs queued work itself. Such a runtime
 * would run activities and append events when the workflow awaits: only a distributed runtime
 * suspends the workflow instead.
 */
final class ReadOnlyPassNeedsDistributedRuntime extends \LogicException implements ExceptionInterface
{
    public function __construct()
    {
        parent::__construct('A read-only pass needs a distributed execution runtime.');
    }
}
