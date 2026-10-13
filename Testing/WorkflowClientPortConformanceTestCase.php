<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowStartOptionsRefused;
use Gplanchat\Durable\Exception\WorkflowTypeMismatch;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use Gplanchat\Durable\TaskQueue;
use Gplanchat\Durable\WorkflowStartOptions;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Brings an execution started through the client to the given outcome.
     */
    abstract protected function endRun(ExecutionId $executionId, WorkflowRunStatus $outcome): void;

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
     * @return iterable<string, array{string}>
     */
    public static function operations(): iterable
    {
        foreach (['signal', 'query', 'update', 'result', 'cancel', 'terminate'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('operations')]
    public function testAnUnknownExecutionFailsAtOnceOnEveryOperation(string $operation): void
    {
        $this->expectException(WorkflowExecutionNotFound::class);

        $this->call($operation, ExecutionId::fromString('exec-nobody'));
    }

    /**
     * A query and a result read an ended execution; the rest act on it.
     *
     * @return iterable<string, array{string}>
     */
    public static function actingOperations(): iterable
    {
        foreach (['signal', 'update', 'cancel', 'terminate'] as $operation) {
            yield $operation => [$operation];
        }
    }

    #[DataProvider('actingOperations')]
    public function testAnEndedExecutionRefusesWhatActsOnIt(string $operation): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);
        $this->endRun($id, WorkflowRunStatus::Completed);

        try {
            $this->call($operation, $id);
            self::fail('An ended execution cannot be acted on.');
        } catch (WorkflowExecutionAlreadyEnded $e) {
            self::assertSame('exec-1', $e->executionId);
        }
        self::assertSame(WorkflowRunStatus::Completed, $this->catalogUnderTest()->findRun($id)?->status);
    }

    private function call(string $operation, ExecutionId $id): mixed
    {
        $client = $this->clientUnderTest();

        return match ($operation) {
            'signal' => $client->signal($id, 'pay'),
            'query' => $client->query($id, 'status'),
            'update' => $client->update($id, 'change'),
            'result' => $client->result($id, Duration::seconds(0.1)),
            'cancel' => $client->cancel($id),
            default => $client->terminate($id),
        };
    }

    private function repository(): ConformanceClientWorkflowRepository
    {
        return new ConformanceClientWorkflowRepository($this->catalogUnderTest());
    }
}
