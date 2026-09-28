<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Another worker holds this attempt: run it later, not now and not never.
 *
 * The claim that refused it survives a worker that died holding it until the lock TTL, so the copy
 * is handed back to its host to be delivered again after {@see self::RETRY_AFTER_SECONDS}. By then
 * the holder has journalled the attempt, and the guards answer the copy; or it died, and its claim
 * expires in the end (#590).
 */
final class ActivityAttemptDeferred extends \RuntimeException implements ExceptionInterface
{
    public const RETRY_AFTER_SECONDS = 10;

    public function __construct(
        public readonly string $executionId,
        public readonly string $activityId,
        public readonly int $attempt,
    ) {
        parent::__construct(\sprintf('Durable: attempt %d of activity %s (execution %s) is held by another worker; retry it later.', $attempt, $activityId, $executionId));
    }
}
