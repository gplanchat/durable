<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Conformance suite for {@see WorkflowRunCatalogInterface} — DUR041.
 *
 * This port is **read-only**: the suite therefore cannot fill itself, and asks the adapter for two
 * seeding hooks. This is the shape a conformance suite takes when the port has no write side — the
 * hooks say "make an execution exist in this state", not "write this row".
 *
 * **What the suite does not require:** a precise order between two executions started within the
 * same second. The contract announces "from the most recently started to the oldest", and a backend
 * that dates to the second then has no neutral tiebreak to offer. Requiring an order the port does
 * not promise would fail a correct adapter, which is the surest way to make a suite unusable. What
 * is required, and what is the real guarantee of a dashboard, is that a pagination **loses nothing
 * and shows nothing twice**.
 *
 * @see DUR041
 * @see DUR037
 */
abstract class WorkflowRunCatalogConformanceTestCase extends TestCase
{
    /**
     * The catalog under test, on top of the storage the two hooks fill.
     */
    abstract protected function catalogUnderTest(): WorkflowRunCatalogInterface;

    /**
     * Makes a running execution exist, carrying this workflow type.
     */
    abstract protected function startRun(string $executionId, string $workflowType): void;

    /**
     * Brings an already started execution to its outcome.
     */
    abstract protected function endRun(string $executionId, WorkflowRunStatus $outcome): void;

    /**
     * Whether this catalog can tell a run nobody has picked up yet. Optional: a catalog that cannot
     * leaves the fact absent, and the suite checks that instead.
     */
    protected function canTellAPickup(): bool
    {
        return false;
    }

    /**
     * Records that a worker picked the execution up, as your worker does when it consumes the resume
     * (the core's `ResumeWorkflowHandler` calls `recordPickup()`). Called only when
     * {@see canTellAPickup()} is true. Note that {@see startRun()} may or may not count as a pickup
     * depending on the backend; the suite only relies on {@see dispatchRun()} for a run not picked up.
     */
    protected function pickUp(string $executionId): void {}

    /**
     * Makes a running execution exist that no worker has picked up yet: dispatched, not consumed.
     * By default the same as {@see startRun()}, for a catalog that cannot tell the difference.
     */
    protected function dispatchRun(string $executionId, string $workflowType): void
    {
        $this->startRun($executionId, $workflowType);
    }

    /**
     * The execution id a description reports, the one {@see startRun()} was given. By default the
     * run id, as on a backend where one execution is one run. A backend that gives each run an id of
     * its own and keeps the execution id as the grouping (Temporal, DUR006) returns `groupId`.
     *
     * @see DUR006
     */
    protected function executionIdOf(WorkflowRunDescription $run): string
    {
        return $run->runId;
    }

    // -----------------------------------------------------------------------------------------

    public function testAnEmptyCatalogListsNothing(): void
    {
        $page = $this->catalogUnderTest()->listRuns();

        self::assertSame([], $page->runs);
        self::assertNull($page->nextCursor, 'nothing left to read');
    }

    public function testADescriptionCarriesWhatAViewNeeds(): void
    {
        $this->startRun('exec-1', 'App\\OrderWorkflow');

        $runs = $this->catalogUnderTest()->listRuns()->runs;

        self::assertCount(1, $runs);
        self::assertInstanceOf(WorkflowRunDescription::class, $runs[0]);
        self::assertSame('exec-1', $this->executionIdOf($runs[0]));
        self::assertSame('App\\OrderWorkflow', $runs[0]->workflowName);
        self::assertSame(WorkflowRunStatus::Running, $runs[0]->status);
    }

    public function testARunNobodyPickedUpSaysSinceWhenAndNoOtherRunDoes(): void
    {
        $this->dispatchRun('waiting', 'App\\OrderWorkflow');
        $this->dispatchRun('picked', 'App\\OrderWorkflow');
        $this->startRun('ended', 'App\\OrderWorkflow');
        if ($this->canTellAPickup()) {
            $this->pickUp('picked');
        }
        $this->endRun('ended', WorkflowRunStatus::Completed);

        $page = $this->catalogUnderTest()->listRuns();
        self::assertSame($this->canTellAPickup(), $page->tellsWaitingForWorker, 'the page says whether the fact can be read at all');
        $runs = [];
        foreach ($page->runs as $run) {
            $runs[$this->executionIdOf($run)] = $run;
        }

        self::assertNull($runs['picked']->waitingForWorkerSince);
        self::assertNull($runs['ended']->waitingForWorkerSince, 'an ended run waits for nothing');
        if (!$this->canTellAPickup()) {
            self::assertNull($runs['waiting']->waitingForWorkerSince, 'a fact the catalog cannot tell is absent');

            return;
        }
        self::assertEquals($runs['waiting']->startedAt, $runs['waiting']->waitingForWorkerSince);
    }

    /**
     * @return iterable<string, array{WorkflowRunStatus}>
     */
    public static function terminalStatuses(): iterable
    {
        yield 'completed' => [WorkflowRunStatus::Completed];
        yield 'failed' => [WorkflowRunStatus::Failed];
        yield 'cancelled' => [WorkflowRunStatus::Cancelled];
        yield 'continued as new' => [WorkflowRunStatus::ContinuedAsNew];
    }

    #[DataProvider('terminalStatuses')]
    public function testAnOutcomeIsVisibleOnTheDescription(WorkflowRunStatus $outcome): void
    {
        $this->startRun('exec-1', 'App\\OrderWorkflow');
        $this->endRun('exec-1', $outcome);

        $runs = $this->catalogUnderTest()->listRuns()->runs;

        self::assertCount(1, $runs);
        self::assertSame($outcome, $runs[0]->status);
        self::assertFalse($runs[0]->status->isRunning());
    }

    public function testFilteringByStatusReturnsOnlyMatchingRuns(): void
    {
        $this->startRun('exec-running', 'App\\OrderWorkflow');
        $this->startRun('exec-done', 'App\\OrderWorkflow');
        $this->startRun('exec-failed', 'App\\OrderWorkflow');
        $this->endRun('exec-done', WorkflowRunStatus::Completed);
        $this->endRun('exec-failed', WorkflowRunStatus::Failed);

        $catalog = $this->catalogUnderTest();

        self::assertSame(['exec-running'], $this->idsOf($catalog->listRuns(WorkflowRunStatus::Running)->runs));
        self::assertSame(['exec-done'], $this->idsOf($catalog->listRuns(WorkflowRunStatus::Completed)->runs));
        self::assertSame(['exec-failed'], $this->idsOf($catalog->listRuns(WorkflowRunStatus::Failed)->runs));
        self::assertSame([], $this->idsOf($catalog->listRuns(WorkflowRunStatus::Cancelled)->runs));
    }

    public function testNoFilterListsEveryOutcomeTogether(): void
    {
        $this->startRun('exec-running', 'App\\OrderWorkflow');
        $this->startRun('exec-done', 'App\\OrderWorkflow');
        $this->endRun('exec-done', WorkflowRunStatus::Completed);

        self::assertSame(
            ['exec-done', 'exec-running'],
            self::sorted($this->idsOf($this->catalogUnderTest()->listRuns()->runs)),
        );
    }

    /**
     * The guarantee that matters for a view. The executions are created in a row, so very probably
     * within the same second: this is exactly the case where an offset cursor makes the window
     * slide.
     */
    public function testPagingLosesNothingAndRepeatsNothing(): void
    {
        $expected = [];
        for ($i = 0; $i < 7; ++$i) {
            $id = \sprintf('exec-%d', $i);
            $this->startRun($id, 'App\\OrderWorkflow');
            $expected[] = $id;
        }

        self::assertSame($expected, self::sorted($this->collectEveryPage(null, 2)));
    }

    public function testAFilteredListingPagesTheSameWay(): void
    {
        $expected = [];
        for ($i = 0; $i < 5; ++$i) {
            $id = \sprintf('exec-done-%d', $i);
            $this->startRun($id, 'App\\OrderWorkflow');
            $this->endRun($id, WorkflowRunStatus::Completed);
            $expected[] = $id;
        }
        $this->startRun('exec-running', 'App\\OrderWorkflow');

        self::assertSame($expected, self::sorted($this->collectEveryPage(WorkflowRunStatus::Completed, 2)));
    }

    public function testAPageThatExhaustsTheCatalogCarriesNoCursor(): void
    {
        $this->startRun('exec-1', 'App\\OrderWorkflow');
        $this->startRun('exec-2', 'App\\OrderWorkflow');

        $page = $this->catalogUnderTest()->listRuns(null, null, 20);

        self::assertCount(2, $page->runs);
        self::assertNull($page->nextCursor);
    }

    public function testReadingTheHistoryOfAnUnknownRunIsEmptyRatherThanAnError(): void
    {
        $absent = new WorkflowRunDescription('exec-nobody', 'App\\Nothing', WorkflowRunStatus::Running);

        self::assertSame([], $this->catalogUnderTest()->readHistory($absent));
    }

    public function testHistoryComesBackInRecordedOrder(): void
    {
        $this->startRun('exec-1', 'App\\OrderWorkflow');
        $this->endRun('exec-1', WorkflowRunStatus::Completed);

        $runs = $this->catalogUnderTest()->listRuns()->runs;
        self::assertCount(1, $runs);

        $history = $this->catalogUnderTest()->readHistory($runs[0]);

        $previous = null;
        foreach ($history as $event) {
            self::assertInstanceOf(WorkflowRunEvent::class, $event);
            if (null !== $previous) {
                self::assertGreaterThan($previous, $event->sequence, 'sequences must grow');
            }
            $previous = $event->sequence;
        }
    }

    /**
     * "Never throws" is in the contract: a failing probe is a diagnostic, not a failure of the
     * caller.
     */
    public function testCheckingHealthAnswersRatherThanThrows(): void
    {
        $health = $this->catalogUnderTest()->checkHealth();

        self::assertNotSame('', $health->backend, 'a health report must say which backend it speaks of');
        self::assertNotSame('', $health->message);
        self::assertTrue($health->reachable, 'the test storage is reachable by construction');
    }

    // -----------------------------------------------------------------------------------------

    /**
     * @return list<string>
     */
    private function collectEveryPage(?WorkflowRunStatus $status, int $limit): array
    {
        $seen = [];
        $cursor = null;
        $guard = 0;

        do {
            $page = $this->catalogUnderTest()->listRuns($status, $cursor, $limit);
            foreach ($this->idsOf($page->runs) as $id) {
                self::assertNotContains($id, $seen, \sprintf('%s showed up twice while paging', $id));
                $seen[] = $id;
            }
            $cursor = $page->nextCursor;
        } while (null !== $cursor && ++$guard < 50);

        self::assertLessThan(50, $guard, 'the paging does not terminate');

        return $seen;
    }

    /**
     * @param list<WorkflowRunDescription> $runs
     *
     * @return list<string>
     */
    private function idsOf(array $runs): array
    {
        return array_map(fn(WorkflowRunDescription $run): string => $this->executionIdOf($run), $runs);
    }

    /**
     * @param list<string> $ids
     *
     * @return list<string>
     */
    private static function sorted(array $ids): array
    {
        sort($ids);

        return $ids;
    }
}
