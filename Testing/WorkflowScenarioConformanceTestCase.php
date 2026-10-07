<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Event\ExecutionCompleted;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\ExecutionId;
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
