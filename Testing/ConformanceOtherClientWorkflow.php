<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * A second workflow, for the cases that need an execution of another type than
 * {@see ConformanceClientWorkflow}: a repository's type mismatch, a second start under another type.
 */
#[AsWorkflow(ConformanceOtherClientWorkflow::TYPE)]
final class ConformanceOtherClientWorkflow
{
    public const TYPE = 'durable.conformance.client.other';

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env, string $tag = ''): string
    {
        $env->sleep(3600);

        return $tag;
    }
}
