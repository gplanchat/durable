<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Every attempt is granted: one process runs its messages one after another.
 */
final class NoActivityAttemptClaim implements ActivityAttemptClaimInterface
{
    public function claim(string $executionId, string $activityId, int $attempt): \Closure
    {
        return static function (): void {};
    }
}
