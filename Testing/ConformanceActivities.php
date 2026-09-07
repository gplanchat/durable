<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\AsActivityMethod;

/**
 * The activity contract that {@see EventStoreReplayConformanceTestCase} schedules. A single method,
 * whose result is not scalar: it is the activity return value that travels through the journal.
 *
 * @see DUR041
 */
interface ConformanceActivities
{
    /**
     * @param list<string> $lines
     */
    #[AsActivityMethod('durable.conformance.quote')]
    public function quote(array $lines): mixed;
}
