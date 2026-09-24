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
     * Whether this catalog keeps what a suspended run waits on (#324). Optional, as the pickup.
     */
    protected function canTellAWait(): bool
    {
        return false;
    }

    /**
     * Records what the execution waits on, as the core's `ResumeWorkflowHandler` does at each
     * suspension. Called only when {@see canTellAWait()} is true.
     */
    protected function recordWait(string $executionId, string $waitingOn): void {}

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
        self::assertSame('exec-1', $runs[0]->runId);
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
            $runs[$run->runId] = $run;
        }

        self::assertNull($runs['picked']->waitingForWorkerSince);
        self::assertNull($runs['ended']->waitingForWorkerSince, 'an ended run waits for nothing');
        if (!$this->canTellAPickup()) {
            self::assertNull($runs['waiting']->waitingForWorkerSince, 'a fact the catalog cannot tell is absent');

            return;
        }
        self::assertEquals($runs['waiting']->startedAt, $runs['waiting']->waitingForWorkerSince);
    }

    public function testASuspendedRunSaysWhatItWaitsOnAndAnEndedRunDoesNot(): void
    {
        $this->startRun('asleep', 'App\\OrderWorkflow');
        $this->startRun('ended', 'App\\OrderWorkflow');
        if ($this->canTellAWait()) {
            $this->recordWait('asleep', 'timer due at 2026-09-24T10:00:00+00:00');
            $this->recordWait('asleep', 'activity charge attempt 2 in flight');
            $this->recordWait('ended', 'activity charge attempt 1 in flight');
        }
        $this->endRun('ended', WorkflowRunStatus::Completed);

        $runs = [];
        foreach ($this->catalogUnderTest()->listRuns()->runs as $run) {
            $runs[$run->runId] = $run;
        }

        self::assertNull($runs['ended']->waitingOn, 'an ended run waits for nothing');
        self::assertSame(
            $this->canTellAWait() ? 'activity charge attempt 2 in flight' : null,
            $runs['asleep']->waitingOn,
            'the latest wait wins; a fact the catalog cannot tell is absent',
        );
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

        self::assertSame(['exec-running'], self::idsOf($catalog->listRuns(WorkflowRunStatus::Running)->runs));
        self::assertSame(['exec-done'], self::idsOf($catalog->listRuns(WorkflowRunStatus::Completed)->runs));
        self::assertSame(['exec-failed'], self::idsOf($catalog->listRuns(WorkflowRunStatus::Failed)->runs));
        self::assertSame([], self::idsOf($catalog->listRuns(WorkflowRunStatus::Cancelled)->runs));
    }

    public function testNoFilterListsEveryOutcomeTogether(): void
    {
        $this->startRun('exec-running', 'App\\OrderWorkflow');
        $this->startRun('exec-done', 'App\\OrderWorkflow');
        $this->endRun('exec-done', WorkflowRunStatus::Completed);

        self::assertSame(
            ['exec-done', 'exec-running'],
            self::sorted(self::idsOf($this->catalogUnderTest()->listRuns()->runs)),
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
            foreach (self::idsOf($page->runs) as $id) {
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
    private static function idsOf(array $runs): array
    {
        return array_map(static fn(WorkflowRunDescription $run): string => $run->runId, $runs);
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
