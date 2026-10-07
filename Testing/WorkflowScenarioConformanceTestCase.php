<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Activity\ActivityOptions;
use Gplanchat\Durable\Activity\RetryLimit;
use Gplanchat\Durable\Duration;
use Gplanchat\Durable\Event\ActivityCompleted;
use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\WorkflowContinuedAsNew;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\ExecutionEngine;
use Gplanchat\Durable\ExecutionId;
use Gplanchat\Durable\ExecutionRuntime;
use Gplanchat\Durable\InMemoryWorkflowRunner;
use Gplanchat\Durable\RegistryActivityExecutor;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\InMemoryActivityTransport;
use Gplanchat\Durable\WorkflowEnvironment;
use Gplanchat\Durable\WorkflowRegistry;
use PHPUnit\Framework\TestCase;

/**
 * One workflow scenario, played on each backend (#984, part of #970).
 *
 * The store suites check what a backend keeps. This one checks what a workflow does when that
 * backend keeps its journal: the same scenarios, with the same expected results, on every
 * subclass. A subclass supplies the journal ({@see createEventStore()}); the runner drives the
 * workflow to quiescence on it, with a virtual clock that moves from one due timer to the next,
 * so that a scenario never waits in real time.
 *
 * A scenario that cannot hold on a backend is listed in {@see namedExceptions()} with its reason
 * and the test reports it as incomplete. It is never skipped without a word and never removed.
 *
 * The Temporal leg is not here: a server runs the same scenarios in the integration suite.
 *
 * @see EventStoreConformanceTestCase for the store half of DUR041
 */
abstract class WorkflowScenarioConformanceTestCase extends TestCase
{
    /**
     * A fresh, empty journal on the backend under test.
     */
    abstract protected function createEventStore(): EventStoreInterface;

    /**
     * The scenarios this backend cannot hold, as `testName => reason (with an issue number)`.
     *
     * @return array<string, string>
     */
    protected function namedExceptions(): array
    {
        return [];
    }

    public function testAWorkflowStartsAndReturnsItsResult(): void
    {
        $this->assertHolds(__FUNCTION__);
        $store = $this->createEventStore();

        $result = $this->runner($store)->run(
            ExecutionId::fromString('scenario-start'),
            static fn(WorkflowEnvironment $wf): array => ['hello' => 'world'],
        );

        self::assertSame(['hello' => 'world'], $result);
        self::assertCount(1, $this->eventsOf($store, 'scenario-start', ExecutionCompleted::class), 'the journal records the completion once');
    }

    public function testATimerFiresAndTheWorkflowGoesOn(): void
    {
        $this->assertHolds(__FUNCTION__);
        $store = $this->createEventStore();

        $result = $this->runner($store)->run(
            ExecutionId::fromString('scenario-timer'),
            static function (WorkflowEnvironment $wf): string {
                $wf->sleep(3600);

                return 'woke';
            },
        );

        self::assertSame('woke', $result);
        self::assertCount(1, $this->eventsOf($store, 'scenario-timer', TimerCompleted::class));
    }

    public function testAnActivityThatFailsOnceIsRetriedAndSucceeds(): void
    {
        $this->assertHolds(__FUNCTION__);
        $store = $this->createEventStore();
        $attempts = 0;
        $activities = new RegistryActivityExecutor();
        $activities->register('durable.conformance.quote', static function () use (&$attempts): array {
            if (1 === ++$attempts) {
                throw new \RuntimeException('first attempt fails');
            }

            return ['total' => 7];
        });

        $result = $this->runner($store, $activities)->run(
            ExecutionId::fromString('scenario-retry'),
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->activityStub(ConformanceActivities::class, new ActivityOptions(
                retryLimit: RetryLimit::ofAttempts(3),
                initialInterval: Duration::seconds(0.001),
                backoffCoefficient: 1.0,
            ))->quote([])),
        );

        self::assertSame(['total' => 7], $result);
        self::assertSame(2, $attempts, 'one failure, one success');
        self::assertCount(1, $this->eventsOf($store, 'scenario-retry', ActivityCompleted::class));
    }

    public function testASignalReachesTheHandlerTheWorkflowDeclares(): void
    {
        $this->assertHolds(__FUNCTION__);
        $store = $this->createEventStore();
        $engine = new ExecutionEngine(
            $store,
            new ExecutionRuntime($store, new InMemoryActivityTransport(), new RegistryActivityExecutor(), 0, null, true),
        );
        $id = ExecutionId::fromString('scenario-signal');
        $handler = static function (WorkflowEnvironment $wf): array {
            $received = [];
            $wf->onSignal('approve', static function (array $payload) use (&$received): void {
                $received[] = $payload;
            });
            $wf->await(static function () use (&$received): bool {
                return [] !== $received;
            });

            return $received;
        };

        try {
            $engine->start($id, $handler);
            self::fail('the workflow must wait for its signal');
        } catch (WorkflowSuspendedException) {
        }
        $store->append(new WorkflowSignalReceived($id, 'approve', ['by' => 'alice']));

        self::assertSame([['by' => 'alice']], $engine->resume($id, $handler));
    }

    public function testAChildWorkflowReturnsItsResultToItsParent(): void
    {
        $this->assertHolds(__FUNCTION__);
        $store = $this->createEventStore();
        $registry = new WorkflowRegistry();
        $registry->registerClass(ConformanceChildWorkflow::class);

        $result = $this->runner($store, null, $registry)->run(
            ExecutionId::fromString('scenario-child'),
            static fn(WorkflowEnvironment $wf): mixed => $wf->await($wf->childWorkflowStub(ConformanceChildWorkflow::class)->run('hello')),
        );

        self::assertSame(['echo' => 'hello'], $result);
        self::assertCount(1, $this->eventsOf($store, 'scenario-child', ChildWorkflowCompleted::class));
    }

    public function testContinueAsNewFollowsTheChainToItsLastRun(): void
    {
        $this->assertHolds(__FUNCTION__);
        $store = $this->createEventStore();
        $registry = new WorkflowRegistry();
        $registry->registerClass(ConformanceCountdownWorkflow::class);

        $result = $this->runner($store, null, $registry)->run(
            ExecutionId::fromString('scenario-chain'),
            $registry->getHandler(ConformanceCountdownWorkflow::class, ['n' => 0]),
        );

        self::assertSame(['runs' => 3], $result);
        self::assertCount(1, $this->eventsOf($store, 'scenario-chain', WorkflowContinuedAsNew::class), 'the first run hands over to its successor');
    }

    protected function runner(
        EventStoreInterface $store,
        ?RegistryActivityExecutor $activities = null,
        ?WorkflowRegistry $registry = null,
    ): InMemoryWorkflowRunner {
        return new InMemoryWorkflowRunner(
            $store,
            new InMemoryActivityTransport(),
            $activities ?? new RegistryActivityExecutor(),
            0,
            $registry ?? new WorkflowRegistry(),
        );
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $eventClass
     *
     * @return list<T>
     */
    protected function eventsOf(EventStoreInterface $store, string $executionId, string $eventClass): array
    {
        $events = [];
        foreach ($store->readStream(ExecutionId::fromString($executionId)) as $event) {
            if ($event instanceof $eventClass) {
                $events[] = $event;
            }
        }

        return $events;
    }

    /**
     * Ends the test as incomplete, with the reason, when the backend lists the scenario.
     */
    protected function assertHolds(string $scenario): void
    {
        $reason = $this->namedExceptions()[$scenario] ?? null;
        if (null !== $reason) {
            self::markTestIncomplete(\sprintf('Named exception, %s: %s', $scenario, $reason));
        }
    }
}
