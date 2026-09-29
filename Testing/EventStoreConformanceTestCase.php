<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Event\ActivityCancelled;
use Gplanchat\Durable\Event\ActivityCatastrophicFailure;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ActivityFailed;
use Gplanchat\Durable\Event\ActivityRetryQueued;
use Gplanchat\Durable\Event\ActivityScheduled;
use Gplanchat\Durable\Event\ActivityTaskCompleted;
use Gplanchat\Durable\Event\ActivityTaskFailed;
use Gplanchat\Durable\Event\ActivityTaskStarted;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\ChildWorkflowScheduled;
use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\ExecutionStarted;
use Gplanchat\Durable\Event\SideEffectRecorded;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\TimerScheduled;
use Gplanchat\Durable\Event\VersionMarked;
use Gplanchat\Durable\Event\WorkflowCancellationDelivered;
use Gplanchat\Durable\Event\WorkflowCancellationRequested;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowExecutionCancelled;
use Gplanchat\Durable\Event\WorkflowExecutionFailed;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Event\WorkflowUpdateHandled;
use Gplanchat\Durable\Exception\SupersededPassException;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\Failure\ActivityRetryState;
use Gplanchat\Durable\Failure\FailureEnvelope;
use Gplanchat\Durable\Mapping\EventDataMapper;
use Gplanchat\Durable\ParentClosePolicy;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Store\FencedEventStoreInterface;
use PHPUnit\Framework\TestCase;

/**
 * Conformance suite for {@see EventStoreInterface} — DUR041.
 *
 * An adapter proves it implements the port by extending this class and returning a fresh store
 * from {@see createEventStore()}. The reference is
 * {@see \Gplanchat\Durable\Store\InMemoryEventStore}, and it replays the suite too: a reference
 * nobody checks is a definition, not a guard.
 *
 * What the suite guards is the failure that does not show at write time. A round trip that distorts
 * a payload breaks nothing at `append()` time; it breaks the replay, later, on an execution that
 * resumes and reads something other than what it had written.
 *
 * ponytail: no parameterizable event factory — the list in {@see mappedEventFixtures()} is
 * hardcoded, and {@see testEveryEventTypeIsCoveredOrExplicitlyExcluded} keeps it up to date in
 * place of a convention nobody rereads.
 *
 * @see DUR041
 */
abstract class EventStoreConformanceTestCase extends TestCase
{
    /**
     * An empty store, ready to receive. Called once per case.
     */
    abstract protected function createEventStore(): EventStoreInterface;

    /**
     * DUR053: whether the store under test must fence passes. False by default, so a third-party
     * store keeps passing; a store that should fence and does not then fails the suite.
     */
    protected function expectsFencedPasses(): bool
    {
        return false;
    }

    // -----------------------------------------------------------------------------------------
    // The cases
    // -----------------------------------------------------------------------------------------

    public function testAStoreThatShouldFencePassesDoes(): void
    {
        $store = $this->createEventStore();
        if (!$this->expectsFencedPasses()) {
            self::assertInstanceOf(EventStoreInterface::class, $store, 'a store need not fence');

            return;
        }

        self::assertInstanceOf(FencedEventStoreInterface::class, $store);
    }

    /** DUR053, #505: once a newer pass claims the execution, the older one can no longer append. */
    public function testAnOlderPassIsRefusedOnceANewerOneClaims(): void
    {
        $store = $this->fencedStore();
        if (null === $store) {
            return;
        }
        $older = $store->claimPass(ExecutionId::fromString('exec-fence'));
        $newer = $store->claimPass(ExecutionId::fromString('exec-fence'));

        $store->appendFenced(new ExecutionStarted(ExecutionId::fromString('exec-fence'), ['by' => 'newer']), $newer);

        try {
            $store->appendFenced(new ExecutionStarted(ExecutionId::fromString('exec-fence'), ['by' => 'older']), $older);
            self::fail('a superseded pass must not append');
        } catch (SupersededPassException) {
        }

        self::assertSame(1, $store->countEventsInStream(ExecutionId::fromString('exec-fence')));
    }

    /** DUR053: a fact from outside the pass (an activity outcome, a signal) carries no fence and invalidates nothing. */
    public function testAnAppendFromOutsideThePassNeitherNeedsNorBreaksAFence(): void
    {
        $store = $this->fencedStore();
        if (null === $store) {
            return;
        }
        $pass = $store->claimPass(ExecutionId::fromString('exec-outside'));

        $store->append(new WorkflowSignalReceived(ExecutionId::fromString('exec-outside'), 'approve', []));
        $store->appendFenced(new TimerCompleted(ExecutionId::fromString('exec-outside'), 'timer-1'), $pass);

        self::assertSame(2, $store->countEventsInStream(ExecutionId::fromString('exec-outside')));
    }

    public function testEachExecutionHasItsOwnEpoch(): void
    {
        $store = $this->fencedStore();
        if (null === $store) {
            return;
        }
        $first = $store->claimPass(ExecutionId::fromString('exec-a'));
        $store->claimPass(ExecutionId::fromString('exec-b'));

        $store->appendFenced(new TimerCompleted(ExecutionId::fromString('exec-a'), 'timer-1'), $first);

        self::assertSame(1, $store->countEventsInStream(ExecutionId::fromString('exec-a')));
    }

    public function testAStreamComesBackInInsertionOrder(): void
    {
        $store = $this->createEventStore();
        $expected = [];

        foreach (self::mappedEventFixtures('exec-order') as $event) {
            $store->append($event);
            $expected[] = $event::class;
        }

        self::assertSame($expected, self::classesOf($store->readStream(ExecutionId::fromString('exec-order'))));
    }

    /**
     * The central case. The comparison goes through the record rather than the object: it is the
     * record that travels through the storage, and two equal instances prove nothing if the shape
     * on disk moved under them.
     */
    public function testEveryMappedEventTypeSurvivesTheRoundTrip(): void
    {
        $store = $this->createEventStore();
        $fixtures = self::mappedEventFixtures('exec-fidelity');

        foreach ($fixtures as $event) {
            $store->append($event);
        }

        $readBack = iterator_to_array($store->readStream(ExecutionId::fromString('exec-fidelity')), false);

        self::assertCount(\count($fixtures), $readBack, 'the stream must return as many events as it was given');

        foreach (array_values($fixtures) as $index => $original) {
            self::assertSame(
                EventDataMapper::fromDomainEvent($original),
                EventDataMapper::fromDomainEvent($readBack[$index]),
                \sprintf('%s does not survive the round trip', $original::class),
            );
        }
    }

    /**
     * The DBAL store returns a generator backed by a query, the in-memory store an array. Both
     * halves count: one pass is enough, **and** a second call starts again from the beginning. It
     * is the second one an adapter misses — a generator memoized once does not replay.
     */
    public function testAStreamIsConsumableOnceAndRestartsOnTheNextCall(): void
    {
        $store = $this->createEventStore();
        foreach (self::mappedEventFixtures('exec-passes') as $event) {
            $store->append($event);
        }

        $first = self::classesOf($store->readStream(ExecutionId::fromString('exec-passes')));
        $second = self::classesOf($store->readStream(ExecutionId::fromString('exec-passes')));

        self::assertNotSame([], $first);
        self::assertSame($first, $second, 'reading the stream again must return the same thing, not nothing');
    }

    public function testAPartiallyConsumedStreamDoesNotDisturbTheNextRead(): void
    {
        $store = $this->createEventStore();
        foreach (self::mappedEventFixtures('exec-partial') as $event) {
            $store->append($event);
        }

        foreach ($store->readStream(ExecutionId::fromString('exec-partial')) as $ignored) {
            break; // abandon the stream on its first element
        }

        self::assertSame(
            \count(self::mappedEventFixtures('exec-partial')),
            \count(self::classesOf($store->readStream(ExecutionId::fromString('exec-partial')))),
        );
    }

    public function testAStreamCarriesOneExecutionOnly(): void
    {
        $store = $this->createEventStore();

        $store->append(new ExecutionStarted(ExecutionId::fromString('exec-a'), ['who' => 'a']));
        $store->append(new ExecutionStarted(ExecutionId::fromString('exec-b'), ['who' => 'b']));
        $store->append(new ExecutionCompleted(ExecutionId::fromString('exec-a'), 'done-a'));

        $streamA = iterator_to_array($store->readStream(ExecutionId::fromString('exec-a')), false);
        $streamB = iterator_to_array($store->readStream(ExecutionId::fromString('exec-b')), false);

        self::assertCount(2, $streamA);
        self::assertCount(1, $streamB);
        foreach ($streamA as $event) {
            self::assertSame('exec-a', $event->executionId()->toString());
        }
        self::assertSame('exec-b', $streamB[0]->executionId()->toString());
    }

    public function testCountingAgreesWithTheStreamLength(): void
    {
        $store = $this->createEventStore();
        $fixtures = self::mappedEventFixtures('exec-count');

        self::assertSame(0, $store->countEventsInStream(ExecutionId::fromString('exec-count')), 'an empty stream counts zero');

        foreach (array_values($fixtures) as $index => $event) {
            $store->append($event);
            self::assertSame(
                $index + 1,
                $store->countEventsInStream(ExecutionId::fromString('exec-count')),
                'the count must follow every write',
            );
        }

        self::assertSame(
            \count(self::classesOf($store->readStream(ExecutionId::fromString('exec-count')))),
            $store->countEventsInStream(ExecutionId::fromString('exec-count')),
        );
    }

    /**
     * `recordedAt` is nullable by contract — the in-memory store dates nothing. So the suite guards
     * the **shape** and the order, not a value.
     */
    public function testRecordedAtYieldsTheSameEventsInTheSameOrder(): void
    {
        $store = $this->createEventStore();
        foreach (self::mappedEventFixtures('exec-dated') as $event) {
            $store->append($event);
        }

        $plain = self::classesOf($store->readStream(ExecutionId::fromString('exec-dated')));
        $dated = [];

        foreach ($store->readStreamWithRecordedAt(ExecutionId::fromString('exec-dated')) as $entry) {
            self::assertArrayHasKey('event', $entry);
            self::assertArrayHasKey('recordedAt', $entry);
            self::assertInstanceOf(Event::class, $entry['event']);
            if (null !== $entry['recordedAt']) {
                self::assertInstanceOf(\DateTimeImmutable::class, $entry['recordedAt']);
            }
            $dated[] = $entry['event']::class;
        }

        self::assertSame($plain, $dated);
    }

    public function testAnUnknownExecutionIsEmptyRatherThanAnError(): void
    {
        $store = $this->createEventStore();
        $store->append(new ExecutionStarted(ExecutionId::fromString('exec-known'), []));

        self::assertSame([], self::classesOf($store->readStream(ExecutionId::fromString('exec-nobody'))));
        self::assertSame([], iterator_to_array($store->readStreamWithRecordedAt(ExecutionId::fromString('exec-nobody')), false));
        self::assertSame(0, $store->countEventsInStream(ExecutionId::fromString('exec-nobody')));
    }

    /**
     * The guard DUR041 leaves behind: a suite only proves what it is taught to ask. Adding an event
     * type without filing it on one side or the other fails here, rather than widening the journal
     * without widening the guard.
     */
    public function testEveryEventTypeIsCoveredOrExplicitlyExcluded(): void
    {
        $declared = [];
        foreach (glob(__DIR__ . '/../Event/*.php') ?: [] as $file) {
            $short = basename($file, '.php');
            if ('Event' === $short) {
                continue; // the interface itself
            }
            $declared[] = 'Gplanchat\\Durable\\Event\\' . $short;
        }
        sort($declared);

        $accounted = array_merge(
            array_keys(self::mappedEventFixtures('exec-coverage')),
            self::eventTypesOutsideTheJournal(),
        );
        sort($accounted);

        self::assertSame(
            $declared,
            $accounted,
            'an event type must have a fixture, or be listed in eventTypesOutsideTheJournal()',
        );
    }

    // -----------------------------------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------------------------------

    /**
     * One instance of each type that {@see EventDataMapper} can read back, with deliberately
     * non-scalar payloads: that is where a JSON round trip distorts.
     *
     * @return array<class-string<Event>, Event> indexed by class, so that the coverage guard reads
     *                                           the keys
     */
    final protected static function mappedEventFixtures(string $executionId): array
    {
        // The values that travel badly through a JSON round trip, gathered where they are used: a
        // SKU reference with a leading zero and an integer beyond PHP_INT_MAX do not survive a
        // `JSON_NUMERIC_CHECK` nor a naive cast, and that is the failure the replay will only see
        // after the fact. A mutation that distorts the payload must fail here.
        $nested = [
            'deep' => ['ratio' => 0.125, 'flags' => [true, false], 'label' => 'é✓ "quoted" \\ backslash'],
            'n' => 3,
            'sku' => '0042',
            'beyondIntMax' => '9223372036854775808',
            'exponent' => '1e3',
            'zero' => 0,
            'no' => false,
            'nothing' => null,
            'emptyList' => [],
        ];

        $events = [
            new ExecutionStarted(ExecutionId::fromString($executionId), ['input' => $nested]),
            new ActivityScheduled(ExecutionId::fromString($executionId), 'act-1', 'quote', ['lines' => ['a', 'b']], ['queue' => 'default']),
            new ActivityTaskStarted(ExecutionId::fromString($executionId), 'act-1', 'quote', 1),
            new ActivityRetryQueued(ExecutionId::fromString($executionId), 'act-1', 2),
            new ActivityTaskFailed(ExecutionId::fromString($executionId), 'act-1', 'quote', 1, \RuntimeException::class, 'transient', ActivityRetryState::InProgress),
            new ActivityTaskCompleted(ExecutionId::fromString($executionId), 'act-1', $nested),
            new ActivityCompleted(ExecutionId::fromString($executionId), 'act-1', $nested),
            new VersionMarked(ExecutionId::fromString($executionId), 'add-discount', 1),
            new ActivityFailed(
                ExecutionId::fromString($executionId),
                'act-2',
                \LogicException::class,
                'nope',
                42,
                ['ctx' => $nested],
                '#0 {main}',
                [['class' => \RuntimeException::class, 'message' => 'cause', 'code' => 5]],
                'quote',
                3,
                ActivityRetryState::MaximumAttemptsReached,
            ),
            new ActivityCancelled(ExecutionId::fromString($executionId), 'act-3', 'workflow cancelled'),
            ActivityCatastrophicFailure::fromStoredPayload(ExecutionId::fromString($executionId), [
                'activityId' => 'act-4',
                'activityName' => 'quote',
                'attempt' => 2,
                'exceptionClass' => \Error::class,
                'exceptionMessage' => 'out of memory',
                'reasonCode' => 'fatal',
            ]),
            new TimerScheduled(ExecutionId::fromString($executionId), 'timer-1', 1735689600.5, 'cooldown'),
            new TimerCompleted(ExecutionId::fromString($executionId), 'timer-1'),
            new TimerCancelled(ExecutionId::fromString($executionId), 'timer-2', 'superseded'),
            new SideEffectRecorded(ExecutionId::fromString($executionId), 'side-1', $nested),
            new ChildWorkflowScheduled(
                ExecutionId::fromString($executionId),
                'child-1',
                'Child\\Type',
                ['payload' => $nested],
                ParentClosePolicy::Abandon,
                'requested-id',
                ['queue' => 'children'],
            ),
            new ChildWorkflowCompleted(ExecutionId::fromString($executionId), 'child-1', $nested),
            new ChildWorkflowFailed(ExecutionId::fromString($executionId), 'child-2', 'child blew up', 7, 'workflow_handler_failure', \LogicException::class, ['ctx' => $nested]),
            new WorkflowSignalReceived(ExecutionId::fromString($executionId), 'approve', ['by' => 'someone', 'payload' => $nested], 'request-1'),
            new WorkflowUpdateHandled(
                ExecutionId::fromString($executionId),
                'amend',
                ['delta' => $nested],
                $nested,
                new FailureEnvelope(\LogicException::class, 'rejected', 9, ['ctx' => $nested], '#0 {main}', []),
            ),
            new WorkflowCancellationRequested(ExecutionId::fromString($executionId), 'user asked', 'parent-1'),
            new WorkflowCancellationDelivered(ExecutionId::fromString($executionId), ['act-1', 'timer-1']),
            new WorkflowCancellationDelivered(ExecutionId::fromString($executionId), []),
            new WorkflowExecutionCancelled(ExecutionId::fromString($executionId), 'user asked', 'parent-1'),
            new WorkflowContinuedAsNew(ExecutionId::fromString($executionId), 'Next\\Type', ['carry' => $nested], ['reason' => 'history size'], 'exec-next'),
            WorkflowExecutionFailed::fromStoredPayload(ExecutionId::fromString($executionId), [
                'kind' => WorkflowExecutionFailed::KIND_WORKFLOW_HANDLER,
                'failureClass' => \RuntimeException::class,
                'failureMessage' => 'handler threw',
                'failureCode' => 13,
                'context' => ['ctx' => $nested],
            ]),
            new ExecutionCompleted(ExecutionId::fromString($executionId), $nested),
        ];

        $byClass = [];
        foreach ($events as $event) {
            $byClass[$event::class] = $event;
        }

        return $byClass;
    }

    /**
     * The types the journal does not carry. Nexus is served on the caller side only (DUR036): these
     * events are born from the Temporal history, `EventDataMapper` does not read them back, and an
     * SQL journal will never see any. Excluding them here is a written decision, not an oversight.
     *
     * @return list<class-string<Event>>
     */
    protected static function eventTypesOutsideTheJournal(): array
    {
        return [
            \Gplanchat\Durable\Event\NexusOperationCancelled::class,
            \Gplanchat\Durable\Event\NexusOperationCompleted::class,
            \Gplanchat\Durable\Event\NexusOperationFailed::class,
            \Gplanchat\Durable\Event\NexusOperationScheduled::class,
            \Gplanchat\Durable\Event\NexusOperationTimedOut::class,
        ];
    }

    /**
     * @param iterable<Event> $stream
     *
     * @return list<class-string<Event>>
     */
    private static function classesOf(iterable $stream): array
    {
        $classes = [];
        foreach ($stream as $event) {
            $classes[] = $event::class;
        }

        return $classes;
    }

    private function fencedStore(): ?FencedEventStoreInterface
    {
        $store = $this->createEventStore();
        if (!$store instanceof FencedEventStoreInterface) {
            self::assertFalse($this->expectsFencedPasses(), 'a store that must fence implements FencedEventStoreInterface');

            return null;
        }

        return $store;
    }
}
