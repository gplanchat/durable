<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

use Gplanchat\Durable\ExecutionId;

/**
 * Every attempt is granted: one process runs its messages one after another.
 */
final readonly class NoActivityAttemptClaim implements ActivityAttemptClaimInterface
{
    public function claim(ExecutionId $executionId, string $activityId, int $attempt): \Closure
    {
        return static function (): void {};
    }
}
