<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsUpdateMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * The workflow {@see WorkflowClientPortConformanceTestCase} signals and updates. It waits for the
 * `pay` signal; `change` answers a counter, so two identical updates get different results, and
 * `explode` fails without ending the execution.
 */
#[AsWorkflow(ConformanceUpdatableClientWorkflow::TYPE)]
final class ConformanceUpdatableClientWorkflow
{
    public const TYPE = 'durable.conformance.client.updatable';

    private bool $paid = false;
    private int $changes = 0;

    public function __construct(
        private readonly WorkflowEnvironment $environment,
    ) {}

    /**
     * @param array<string, mixed> $payload
     */
    #[AsSignalMethod('pay')]
    public function pay(array $payload): void
    {
        $this->paid = true;
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[AsUpdateMethod('change')]
    public function change(array $payload): int
    {
        return ++$this->changes;
    }

    /**
     * @param array<string, mixed> $payload
     */
    #[AsUpdateMethod('explode')]
    public function explode(array $payload): never
    {
        throw new ConformanceUpdateRefused();
    }

    #[AsWorkflowMethod]
    public function run(): string
    {
        $this->environment->await(fn(): bool => $this->paid);

        return 'paid';
    }
}
