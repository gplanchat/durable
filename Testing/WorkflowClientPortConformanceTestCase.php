<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Attribute\AsWorkflowRepository;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowStartOptionsRefused;
use Gplanchat\Durable\Exception\WorkflowTypeMismatch;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\WorkflowRepository;
use Gplanchat\Durable\WorkflowStartOptions;
use PHPUnit\Framework\TestCase;

/**
 * Conformance suite for {@see WorkflowClientPort}: every backend subclasses it (openspec change
 * `workflow-repository`, task 1.1).
 */
abstract class WorkflowClientPortConformanceTestCase extends TestCase
{
    abstract protected function clientUnderTest(): WorkflowClientPort;

    /**
     * The catalogue that sees what the client started.
     */
    abstract protected function catalogUnderTest(): WorkflowRunCatalogInterface;

    /**
     * The input the backend holds for an execution started through the client.
     *
     * @return array<string, mixed>|null
     */
    abstract protected function startedInputOf(ExecutionId $executionId): ?array;

    public function testAStartReturnsTheIdAndTheExecutionIsFoundRunning(): void
    {
        $id = ExecutionId::fromString('exec-1');

        $returned = $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE, ['tag' => 'a']);

        self::assertTrue($id->equals($returned));
        $run = $this->catalogUnderTest()->findRun($id);
        self::assertNotNull($run);
        self::assertSame(ConformanceClientWorkflow::TYPE, $run->workflowName);
        self::assertSame(WorkflowRunStatus::Running, $run->status);
        self::assertSame(['tag' => 'a'], $this->startedInputOf($id));
    }

    public function testASecondStartFailsAndTheFirstExecutionKeepsItsTypeAndInput(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE, ['tag' => 'first']);

        try {
            $this->clientUnderTest()->start($id, ConformanceOtherClientWorkflow::TYPE, ['tag' => 'second']);
            self::fail('A second start must fail.');
        } catch (WorkflowExecutionAlreadyStarted $e) {
            self::assertSame('exec-1', $e->executionId);
        }
        self::assertSame(ConformanceClientWorkflow::TYPE, $this->catalogUnderTest()->findRun($id)?->workflowName);
        self::assertSame(['tag' => 'first'], $this->startedInputOf($id));
    }

    public function testAStartWithDefaultOptionsIsNotRefused(): void
    {
        $id = ExecutionId::fromString('exec-1');

        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE, [], WorkflowStartOptions::defaults());

        self::assertNotNull($this->catalogUnderTest()->findRun($id));
    }

    public function testAnOptionTheBackendCannotHonourFailsTheStartAndStartsNothing(): void
    {
        $id = ExecutionId::fromString('exec-1');

        try {
            $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE, [], new WorkflowStartOptions(taskQueue: TaskQueue::named('other')));
            self::fail('A refused option must fail the start.');
        } catch (WorkflowStartOptionsRefused $e) {
            self::assertContains('taskQueue', $e->options);
        }
        self::assertNull($this->catalogUnderTest()->findRun($id));
        self::assertNull($this->startedInputOf($id));
    }

    public function testARepositoryRefusesAnExecutionOfAnotherWorkflowType(): void
    {
        $this->clientUnderTest()->start(ExecutionId::fromString('exec-1'), ConformanceOtherClientWorkflow::TYPE);

        $this->expectException(WorkflowTypeMismatch::class);
        $this->repository()->get(ExecutionId::fromString('exec-1'));
    }

    public function testARepositoryFindsAnExecutionOfItsWorkflowType(): void
    {
        $this->clientUnderTest()->start(ExecutionId::fromString('exec-1'), ConformanceClientWorkflow::TYPE);

        self::assertSame('exec-1', $this->repository()->get(ExecutionId::fromString('exec-1'))->executionId()->toString());
    }

    /**
     * @return WorkflowRepository<ConformanceClientWorkflow>
     */
    private function repository(): WorkflowRepository
    {
        return new #[AsWorkflowRepository(ConformanceClientWorkflow::class)] class ($this->catalogUnderTest()) extends WorkflowRepository {};
    }
}
