<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Waits on a one-hour timer and, when cancelled, compensates by waiting on a two-hour one before it
 * rethrows: a cancelled run stays running while its own code compensates.
 */
#[AsWorkflow(ConformanceCompensatingClientWorkflow::TYPE)]
final class ConformanceCompensatingClientWorkflow
{
    public const TYPE = 'durable.conformance.client.compensating';

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env): string
    {
        try {
            $env->sleep(3600);
        } catch (WorkflowCancelledFailure $cancelled) {
            $env->sleep(7200);

            throw $cancelled;
        }

        return 'done';
    }
}
