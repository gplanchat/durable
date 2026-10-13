<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * The workflow {@see WorkflowClientPortConformanceTestCase} starts and the repository it builds
 * serves. It waits on a one-hour timer, so an execution stays running on every backend until the
 * suite ends it.
 */
#[AsWorkflow(ConformanceClientWorkflow::TYPE)]
final class ConformanceClientWorkflow
{
    public const TYPE = 'durable.conformance.client';

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env, string $tag = ''): string
    {
        $env->sleep(3600);

        return $tag;
    }
}
