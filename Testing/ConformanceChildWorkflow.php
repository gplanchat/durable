<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;

/**
 * The child that {@see EventStoreReplayConformanceTestCase} starts, so that the child lookups of
 * the history port have something to read back from every adapter.
 *
 * @see DUR041
 */
#[AsWorkflow('durable.conformance.child')]
final class ConformanceChildWorkflow
{
    /**
     * @return array{echo: string}
     */
    #[AsWorkflowMethod]
    public function run(string $word): array
    {
        return ['echo' => $word];
    }
}
