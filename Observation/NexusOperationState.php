<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

/**
 * Where a Nexus operation stands, as a dashboard says it.
 *
 * In flight is not a failure: the operation was scheduled and nothing settled it yet, which is the
 * one wait of a run served by someone else — another team, namespace or deployment.
 */
enum NexusOperationState: string
{
    case InFlight = 'in_flight';
    case Completed = 'completed';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return str_replace('_', ' ', $this->value);
    }
}
