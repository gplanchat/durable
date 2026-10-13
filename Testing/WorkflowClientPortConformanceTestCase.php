<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowStartOptionsRefused;
use Gplanchat\Durable\Exception\WorkflowTypeMismatch;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowClientPort;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
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
     * How many events the backend holds for the execution: above zero once a start has run the
     * workflow's first pass, and unchanged by a start that fails.
     */
    abstract protected function historyLengthOf(ExecutionId $executionId): int;

    /**
     * The events the backend holds for the execution, in order.
     *
     * @return list<Event>
     */
    abstract protected function journalOf(ExecutionId $executionId): array;

    /**
     * The start options this backend cannot honour, each with the names the refusal must carry.
     * A backend that honours an option leaves it out.
     *
     * @return iterable<string, array{WorkflowStartOptions, list<string>}>
     */
    abstract public static function refusedStartOptions(): iterable;

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
        self::assertGreaterThan(0, $this->historyLengthOf($id), 'The start only wrote a row: nothing ran the workflow.');
    }

    public function testASecondStartFailsAndTheFirstExecutionKeepsItsTypeAndInput(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE, ['tag' => 'first']);
        $written = $this->historyLengthOf($id);

        try {
            $this->clientUnderTest()->start($id, ConformanceOtherClientWorkflow::TYPE, ['tag' => 'second']);
            self::fail('A second start must fail.');
        } catch (WorkflowExecutionAlreadyStarted $e) {
            self::assertSame('exec-1', $e->executionId);
        }
        self::assertSame(ConformanceClientWorkflow::TYPE, $this->catalogUnderTest()->findRun($id)?->workflowName);
        self::assertSame(['tag' => 'first'], $this->startedInputOf($id));
        self::assertSame($written, $this->historyLengthOf($id));
    }

    public function testAStartWithDefaultOptionsIsNotRefused(): void
    {
        $id = ExecutionId::fromString('exec-1');

        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE, [], WorkflowStartOptions::defaults());

        self::assertNotNull($this->catalogUnderTest()->findRun($id));
    }

    /**
     * @param list<string> $names
     */
    #[DataProvider('refusedStartOptions')]
    public function testAnOptionTheBackendCannotHonourFailsTheStartAndStartsNothing(WorkflowStartOptions $options, array $names): void
    {
        $id = ExecutionId::fromString('exec-1');

        try {
            $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE, [], $options);
            self::fail('A refused option must fail the start.');
        } catch (WorkflowStartOptionsRefused $e) {
            self::assertSame($names, $e->options);
        }
        self::assertNull($this->catalogUnderTest()->findRun($id));
        self::assertNull($this->startedInputOf($id));
        self::assertSame(0, $this->historyLengthOf($id));
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

    /**
     * @return iterable<string, array{string, WorkflowRunStatus}>
     */
    public static function endedExecutions(): iterable
    {
        foreach (self::actingOperations() as $operation => [$name]) {
            foreach ([WorkflowRunStatus::Completed, WorkflowRunStatus::Failed, WorkflowRunStatus::Cancelled] as $outcome) {
                yield $operation . ' on ' . $outcome->value => [$name, $outcome];
            }
        }
    }

    #[DataProvider('endedExecutions')]
    public function testAnEndedExecutionRefusesWhatActsOnIt(string $operation, WorkflowRunStatus $outcome): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);
        $this->endRun($id, $outcome);

        try {
            $this->call($operation, $id);
            self::fail('An ended execution cannot be acted on.');
        } catch (WorkflowExecutionAlreadyEnded $e) {
            self::assertSame('exec-1', $e->executionId);
        }
        self::assertSame($outcome, $this->catalogUnderTest()->findRun($id)?->status);
    }

    public function testASignalReachesTheWorkflowWhichThenCompletes(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceUpdatableClientWorkflow::TYPE);

        $this->clientUnderTest()->signal($id, 'pay', ['by' => 'card'], 'req-1');

        $journal = $this->journalOf($id);
        $signals = array_values(array_filter($journal, static fn(Event $e): bool => $e instanceof WorkflowSignalReceived));
        self::assertCount(1, $signals);
        self::assertSame('pay', $signals[0]->signalName());
        self::assertSame('req-1', $signals[0]->requestId());
        self::assertNotEmpty(array_filter($journal, static fn(Event $e): bool => $e instanceof ExecutionCompleted), 'The workflow did not receive the signal.');
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
