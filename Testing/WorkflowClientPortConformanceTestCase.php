<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Exception\DurableActivityFailedException;
use Gplanchat\Durable\Exception\DurableUpdateFailedException;
use Gplanchat\Durable\Exception\DurableWorkflowAlgorithmFailureException;
use Gplanchat\Durable\Exception\UpdateFailureReason;
use Gplanchat\Durable\Exception\WorkflowCancelledException;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyEnded;
use Gplanchat\Durable\Exception\WorkflowExecutionAlreadyStarted;
use Gplanchat\Durable\Exception\WorkflowExecutionNotFound;
use Gplanchat\Durable\Exception\WorkflowFailedException;
use Gplanchat\Durable\Exception\WorkflowQueryNotFound;
use Gplanchat\Durable\Exception\WorkflowResultTimeout;
use Gplanchat\Durable\Exception\WorkflowStartOptionsRefused;
use Gplanchat\Durable\Exception\WorkflowTerminatedException;
use Gplanchat\Durable\Exception\WorkflowTypeMismatch;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\FailureEnvelope;
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

    public function testAQueryAnswersTheStateBeforeAndAfterASignal(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);

        self::assertSame('waiting', $this->clientUnderTest()->query($id, 'status'));

        $this->clientUnderTest()->signal($id, 'pay');

        self::assertSame('paid', $this->clientUnderTest()->query($id, 'status'));
    }

    public function testAQueryAddsNoEventToTheHistory(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);
        $this->clientUnderTest()->signal($id, 'pay');
        $written = $this->historyLengthOf($id);

        $this->clientUnderTest()->query($id, 'status');
        $this->clientUnderTest()->query($id, 'status');

        self::assertSame($written, $this->historyLengthOf($id));
    }

    public function testAQueryTheWorkflowDoesNotDeclareFailsNamingTheExecutionAndTheQuery(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);

        try {
            $this->clientUnderTest()->query($id, 'nobody-declares-this');
            self::fail('An unknown query name must fail.');
        } catch (WorkflowQueryNotFound $e) {
            self::assertSame('exec-1', $e->executionId);
            self::assertSame('nobody-declares-this', $e->queryName);
        }
    }

    /**
     * Writes the ending event into the journal the way the backend does, so that the catalogue
     * and the journal both tell how the execution ended.
     */
    protected function appendEnding(Event $ending): void
    {
        self::fail('This backend does not override appendEnding(): the result cases cannot end an execution.');
    }

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

    public function testAResultReturnsWhatACompletedExecutionReturned(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);
        $this->appendEnding(new ExecutionCompleted($id, ['done' => true]));

        self::assertSame(['done' => true], $this->clientUnderTest()->result($id, Duration::seconds(1)));
    }

    public function testAResultRethrowsTheFailureOfAFailedExecution(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);
        $this->appendEnding(WorkflowExecutionFailed::workflowHandlerFailure($id, new \DomainException('card refused', 7)));

        try {
            $this->clientUnderTest()->result($id, Duration::seconds(1));
            self::fail('A failed execution must throw its failure.');
        } catch (\DomainException $e) {
            self::assertSame('card refused', $e->getMessage());
            self::assertSame(7, $e->getCode());
        }
    }

    public function testAResultNamesAFailureItCannotRebuild(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);
        $this->appendEnding(WorkflowExecutionFailed::fromStoredPayload($id, [
            'kind' => WorkflowExecutionFailed::KIND_WORKFLOW_HANDLER,
            'failureClass' => 'Acme\\Gone\\NoSuchFailure',
            'failureMessage' => 'card refused',
        ]));

        $this->expectException(WorkflowFailedException::class);
        $this->clientUnderTest()->result($id, Duration::seconds(1));
    }

    public function testAResultThrowsCancelledForACancelledExecution(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);
        $this->appendEnding(new WorkflowExecutionCancelled($id, 'stopped by an operator'));

        try {
            $this->clientUnderTest()->result($id, Duration::seconds(1));
            self::fail('A cancelled execution must throw.');
        } catch (WorkflowCancelledException $e) {
            self::assertSame('exec-1', $e->executionId);
            self::assertSame('stopped by an operator', $e->reason);
        }
    }

    public function testAResultThrowsTerminatedForATerminatedExecution(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);
        $this->appendEnding(WorkflowExecutionFailed::terminatedByParent($id, ExecutionId::fromString('parent-1')));

        try {
            $this->clientUnderTest()->result($id, Duration::seconds(1));
            self::fail('A terminated execution must throw.');
        } catch (WorkflowTerminatedException $e) {
            self::assertSame('exec-1', $e->executionId);
        }
    }

    public function testAResultTimesOutOnARunningExecutionAndLeavesItRunning(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);
        $timeout = Duration::seconds(1);

        try {
            $this->clientUnderTest()->result($id, $timeout);
            self::fail('A running execution must time the wait out.');
        } catch (WorkflowResultTimeout $e) {
            self::assertSame('exec-1', $e->executionId);
            self::assertSame($timeout, $e->timeout);
        }
        self::assertSame(WorkflowRunStatus::Running, $this->catalogUnderTest()->findRun($id)?->status);
    }

    public function testAResultWithoutABoundOnARunningExecutionTimesOutAtOnceWithAnInfiniteBound(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);

        try {
            $this->clientUnderTest()->result($id);
            self::fail('A running execution cannot end during the call.');
        } catch (WorkflowResultTimeout $e) {
            self::assertTrue($e->timeout->isInfinite());
        }
    }

    public function testAResultFollowsAContinueAsNewChainToTheLastRun(): void
    {
        $first = ExecutionId::fromString('exec-1');
        $next = ExecutionId::fromString('exec-2');
        $this->clientUnderTest()->start($first, ConformanceClientWorkflow::TYPE);
        $this->clientUnderTest()->start($next, ConformanceClientWorkflow::TYPE);
        $this->appendEnding(new WorkflowContinuedAsNew($first, ConformanceClientWorkflow::TYPE, [], [], $next));
        $this->appendEnding(new ExecutionCompleted($next, 'last'));

        self::assertSame('last', $this->clientUnderTest()->result($first, Duration::seconds(1)));
    }

    public function testAResultRethrowsTheActivityFailureWithItsCause(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);
        $failed = ActivityFailed::fromEnvelope($id, 'act-1', FailureEnvelope::fromThrowable(new \DomainException('declined')), 'charge', 1);
        $cause = DurableActivityFailedException::fromActivityFailed($failed);
        $this->appendEnding($failed);
        $this->appendEnding(WorkflowExecutionFailed::unhandledActivityFailure($id, 'act-1', 'charge', $cause));

        try {
            $this->clientUnderTest()->result($id, Duration::seconds(1));
            self::fail('A failed execution must throw its failure.');
        } catch (DurableWorkflowAlgorithmFailureException $e) {
            self::assertSame('Workflow did not handle activity failure: ' . $cause->getMessage(), $e->getMessage());
            self::assertInstanceOf(DurableActivityFailedException::class, $e->getPrevious());
            self::assertSame('act-1', $e->getPrevious()->activityId());
        }
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

    public function testTwoIdenticalUpdatesEachGetTheResultOfTheirOwn(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceUpdatableClientWorkflow::TYPE);

        $first = $this->clientUnderTest()->update($id, 'change', ['n' => 1], 'upd-a');
        $second = $this->clientUnderTest()->update($id, 'change', ['n' => 1], 'upd-b');

        self::assertSame([1, 2], [$first, $second]);
        // Each outcome is journalled under the id of the call that asked for it.
        $outcomes = [];
        foreach ($this->journalOf($id) as $event) {
            if ($event instanceof WorkflowUpdateHandled) {
                $outcomes[(string) $event->updateId()] = $event->result();
            }
        }
        self::assertSame(['upd-a' => 1, 'upd-b' => 2], $outcomes);
    }

    public function testAnUpdateSentAgainUnderTheSameIdReturnsTheFirstResultAndRunsNothing(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceUpdatableClientWorkflow::TYPE);
        $this->clientUnderTest()->update($id, 'change', [], 'upd-a');
        $written = $this->historyLengthOf($id);

        self::assertSame(1, $this->clientUnderTest()->update($id, 'change', [], 'upd-a'));
        self::assertSame($written, $this->historyLengthOf($id));
    }

    public function testAnUpdateWithoutAnIdReturnsItsResult(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceUpdatableClientWorkflow::TYPE);

        self::assertSame(1, $this->clientUnderTest()->update($id, 'change'));
    }

    public function testAFailingUpdateThrowsToItsCallerAndTheExecutionCarriesOn(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceUpdatableClientWorkflow::TYPE);

        try {
            $this->clientUnderTest()->update($id, 'explode', [], 'upd-a');
            self::fail('A failing update must throw.');
        } catch (DurableUpdateFailedException $e) {
            self::assertSame('explode', $e->updateName());
            self::assertSame(UpdateFailureReason::HandlerFailed, $e->reason);
        }
        self::assertEmpty(array_filter($this->journalOf($id), static fn(Event $e): bool => $e instanceof ExecutionCompleted));
        self::assertSame(1, $this->clientUnderTest()->update($id, 'change'));
    }

    public function testCancellingAWaitingRunEndsItCancelledAfterTheWorkflowReceivedTheRequest(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);
        $before = $this->historyLengthOf($id);

        $this->clientUnderTest()->cancel($id, 'req-1');

        self::assertSame(WorkflowRunStatus::Cancelled, $this->catalogUnderTest()->findRun($id)?->status);
        self::assertGreaterThan($before, $this->historyLengthOf($id));
    }

    /**
     * Needs {@see ConformanceCompensatingClientWorkflow} registered by the backend.
     */
    public function testACancelledRunStaysRunningWhileItsWorkflowCompensatesAndASecondCancelAddsNothing(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceCompensatingClientWorkflow::TYPE);

        $this->clientUnderTest()->cancel($id);
        $written = $this->historyLengthOf($id);
        $this->clientUnderTest()->cancel($id);

        self::assertSame(WorkflowRunStatus::Running, $this->catalogUnderTest()->findRun($id)?->status, 'The workflow compensates in its own code before the run ends.');
        self::assertSame($written, $this->historyLengthOf($id), 'A second cancel of a run that already has one pending is a no-op.');
    }

    public function testTerminatingAWaitingRunEndsItFailedAndRunsNothingMore(): void
    {
        $id = ExecutionId::fromString('exec-1');
        $this->clientUnderTest()->start($id, ConformanceClientWorkflow::TYPE);

        $this->clientUnderTest()->terminate($id, 'stop');

        self::assertSame(WorkflowRunStatus::Failed, $this->catalogUnderTest()->findRun($id)?->status);
        $this->assertTerminationWasRecorded($id, 'stop');
    }

    /**
     * The backend's proof that the run ended with the `terminated` failure kind and that a later
     * resume of the run changes nothing.
     */
    protected function assertTerminationWasRecorded(ExecutionId $executionId, string $reason): void
    {
        self::fail(\sprintf('%s must override assertTerminationWasRecorded() to prove the termination of "%s".', static::class, $executionId->toString()));
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
