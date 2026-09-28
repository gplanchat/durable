<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port;

/**
 * Claims one attempt of an activity for the worker about to run it.
 *
 * Temporal refuses a second start of the same attempt on the server; a journal backend has no
 * server, and two copies of one message delivered at once both pass the journal guards. The claim
 * stands in for that refusal (#590). A host with a lock shared between its workers implements it;
 * {@see NoActivityAttemptClaim} is for one process, where no copy runs beside another.
 */
interface ActivityAttemptClaimInterface
{
    /**
     * @return (\Closure(): void)|null the release, or null when another worker holds the attempt
     */
    public function claim(string $executionId, string $activityId, int $attempt): ?\Closure;
}
