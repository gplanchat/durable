<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * The handler of an update threw: the caller receives the failure, the execution carries on.
 *
 * That is the whole difference between an update and a signal — an update answers, and its
 * answer may be a failure without the workflow being affected by it (ADR DUR035).
 */
final class DurableUpdateFailedException extends \RuntimeException implements ExceptionInterface
{
    public function __construct(
        private readonly string $updateName,
        string $failureMessage,
        public readonly UpdateFailureReason $reason = UpdateFailureReason::HandlerFailed,
    ) {
        parent::__construct(\sprintf('Update "%s" failed: %s', $updateName, $failureMessage));
    }

    /**
     * The workflow applied nothing: the bridge client receives this as a rejection from the server.
     */
    public static function notHandled(string $updateName): self
    {
        return new self($updateName, 'the workflow did not handle it', UpdateFailureReason::NotHandled);
    }

    public function updateName(): string
    {
        return $this->updateName;
    }
}
