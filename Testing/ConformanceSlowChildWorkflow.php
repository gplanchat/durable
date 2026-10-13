<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * A child that sleeps a minute, for the scenario that bounds it with an execution timeout.
 */
#[AsWorkflow('durable.conformance.slow_child')]
final readonly class ConformanceSlowChildWorkflow
{
    public function __construct(
        private WorkflowEnvironment $environment,
    ) {}

    #[AsWorkflowMethod]
    public function run(): string
    {
        $this->environment->await($this->environment->timer(60));

        return 'slept';
    }
}
