<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The handler of an update threw: the caller receives the failure, the execution carries on.
 *
 * That is the whole difference between an update and a signal — an update answers, and its
 * answer may be a failure without the workflow being affected by it (ADR DUR035).
 */
final class DurableUpdateFailedException extends \RuntimeException
{
    public function __construct(
        private readonly string $updateName,
        string $failureMessage,
    ) {
        parent::__construct(\sprintf('Update "%s" failed: %s', $updateName, $failureMessage));
    }

    public function updateName(): string
    {
        return $this->updateName;
    }
}
