<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsQueryMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * The workflow {@see WorkflowClientPortConformanceTestCase} starts and the repository it builds
 * serves. It waits for the `pay` signal, then on a one-hour timer, so an execution stays running
 * on every backend until the suite ends it. The `status` query answers `waiting`, then `paid`.
 */
#[AsWorkflow(ConformanceClientWorkflow::TYPE)]
final class ConformanceClientWorkflow
{
    public const TYPE = 'durable.conformance.client';

    private string $status = 'waiting';

    #[AsWorkflowMethod]
    public function run(WorkflowEnvironment $env, string $tag = ''): string
    {
        $env->await(fn(): bool => 'paid' === $this->status, label: 'signal pay');
        $env->sleep(3600);

        return $tag;
    }

    #[AsSignalMethod('pay')]
    public function pay(): void
    {
        $this->status = 'paid';
    }

    #[AsQueryMethod('status')]
    public function status(): string
    {
        return $this->status;
    }
}
