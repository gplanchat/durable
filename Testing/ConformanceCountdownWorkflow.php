<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Continues as new until it has done so twice, then returns: the chain
 * {@see WorkflowScenarioConformanceTestCase} follows on every backend.
 *
 * @see DUR041
 */
#[AsWorkflow('durable.conformance.countdown')]
final readonly class ConformanceCountdownWorkflow
{
    public function __construct(private WorkflowEnvironment $environment) {}

    /**
     * @return array{runs: int}
     */
    #[AsWorkflowMethod]
    public function run(int $n = 0): array
    {
        if ($n >= 2) {
            return ['runs' => $n + 1];
        }

        $this->environment->continueAsNew(self::class, ['n' => $n + 1]);
    }
}
