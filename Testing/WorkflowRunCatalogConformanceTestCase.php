<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Testing;

use Gplanchat\Durable\Exception\RunFilterUnavailableException;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunEvent;
use Gplanchat\Durable\Observation\WorkflowRunFilter;
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
     * suspension; null when the wait has no words, which clears the previous one. Called only when
     * {@see canTellAWait()} is true.
     */
    protected function recordWait(string $executionId, ?string $waitingOn): void {}

    /**
     * The execution id a description reports, the one {@see startRun()} was given: `executionId`,
     * on every backend since #514. Kept for the subclasses that override it.
     */
    protected function executionIdOf(WorkflowRunDescription $run): string
    {
        return $run->executionId;
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

    /**
     * A run has an address (#264): the id the list gave it finds it again, with the facts the list
     * gave, and without paging until it shows up.
     */
    public function testARunTheListShowsIsFoundByTheIdTheListGaveIt(): void
    {
        $this->dispatchRun('exec-1', 'App\\OrderWorkflow');
        $this->startRun('exec-2', 'App\\OrderWorkflow');
        if ($this->canTellAWait()) {
            $this->recordWait('exec-2', 'activity charge attempt 1 in flight');
        }
        $this->startRun('exec-3', 'App\\OrderWorkflow');
        $this->endRun('exec-3', WorkflowRunStatus::Completed);

        $listed = $this->catalogUnderTest()->listRuns()->runs;

        self::assertCount(3, $listed);
        foreach ($listed as $run) {
            self::assertEquals($run, $this->catalogUnderTest()->findRun($run->executionId), $this->executionIdOf($run) . ' is found as it is listed');
        }
    }

    public function testAnUnknownRunIsNotFound(): void
    {
        $this->startRun('exec-1', 'App\\OrderWorkflow');

        self::assertNull($this->catalogUnderTest()->findRun('exec-nobody'));
        self::assertNull($this->catalogUnderTest()->findRun('0199a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'), 'an id shaped like a run id, known to nobody');
    }

    /**
     * #514: every catalog reports the id the application started the run with, whatever id the
     * backend gives the run itself. That is the id a log line, an exception or `diagnose` names.
     */
    public function testARunCarriesTheIdTheApplicationStartedItWith(): void
    {
        $this->startRun('exec-1', 'App\\OrderWorkflow');

        self::assertSame(['exec-1'], array_map(static fn(WorkflowRunDescription $run): string => $run->executionId, $this->catalogUnderTest()->listRuns()->runs));
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
            $runs[$this->executionIdOf($run)] = $run;
        }

        self::assertNull($runs['ended']->waitingOn, 'an ended run waits for nothing');
        self::assertSame(
            $this->canTellAWait() ? 'activity charge attempt 2 in flight' : null,
            $runs['asleep']->waitingOn,
            'the latest wait wins; a fact the catalog cannot tell is absent',
        );
    }

    public function testAWaitWithoutWordsClearsThePreviousOne(): void
    {
        $this->startRun('exec-1', 'App\\OrderWorkflow');
        if ($this->canTellAWait()) {
            $this->recordWait('exec-1', 'activity charge attempt 1 in flight');
            $this->recordWait('exec-1', null);
        }

        self::assertNull($this->catalogUnderTest()->listRuns()->runs[0]->waitingOn, 'a stale wait sends the operator to the wrong place');
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

    /**
     * A run that continues as new leaves two rows, the one that ends and its successor (DUR037 §5):
     * on a backend that groups runs, the successor, and only it, may be listed beside the ended run:
     * another run, still running, of the same group. Without a grouping nothing tells it from a
     * stray row, so the ended run stays alone, as for every other outcome.
     */
    #[DataProvider('terminalStatuses')]
    public function testAnOutcomeIsVisibleOnTheDescription(WorkflowRunStatus $outcome): void
    {
        $this->startRun('exec-1', 'App\\OrderWorkflow');
        $this->endRun('exec-1', $outcome);

        $runs = $this->catalogUnderTest()->listRuns()->runs;
        $ended = array_values(array_filter($runs, static fn(WorkflowRunDescription $run): bool => !$run->status->isRunning()));
        $others = array_values(array_filter($runs, static fn(WorkflowRunDescription $run): bool => $run->status->isRunning()));

        self::assertCount(1, $ended);
        self::assertSame($outcome, $ended[0]->status);
        self::assertSame('exec-1', $this->executionIdOf($ended[0]));
        if (WorkflowRunStatus::ContinuedAsNew !== $outcome || null === $ended[0]->groupId) {
            self::assertSame([], $others);

            return;
        }
        self::assertLessThanOrEqual(1, \count($others), 'a continue-as-new leaves one successor at most');
        foreach ($others as $successor) {
            self::assertNotSame($ended[0]->runId, $successor->runId, 'the successor is another run');
            self::assertSame($ended[0]->groupId, $successor->groupId, 'the successor belongs to the same group');
            self::assertSame($ended[0]->executionId, $successor->executionId, 'one execution, two runs: the application\'s id carries over (#514)');
        }
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

    /**
     * #558: a catalog that says it cannot filter refuses a filter, before any backend call, rather
     * than answering an unfiltered page or an empty one. The filter cases below go through here:
     * they check the results where the catalog filters, and the refusal where it does not.
     */
    private function refusesFilters(): bool
    {
        $catalog = $this->catalogUnderTest();
        if ($catalog->canFilterRuns()) {
            return false;
        }

        foreach ([new WorkflowRunFilter(workflowName: 'App\\OrderWorkflow'), new WorkflowRunFilter(executionIdPrefix: 'ord')] as $filter) {
            try {
                $catalog->listRuns(filter: $filter);
                self::fail('a catalog that cannot filter must refuse a filter');
            } catch (RunFilterUnavailableException) {
            }
        }
        self::assertCount(0, $catalog->listRuns(filter: new WorkflowRunFilter('', ''))->runs, 'an empty filter is no filter');

        return true;
    }

    /**
     * #264, #558: the runs of one workflow without paging until they show up. The whole name,
     * backslashes and case included: a FQCN is what most applications name a type.
     */
    public function testFilteringByWorkflowNameReturnsOnlyThatWorkflow(): void
    {
        if ($this->refusesFilters()) {
            return;
        }

        $this->startRun('exec-order', 'App\\OrderWorkflow');
        $this->startRun('exec-report', 'App\\ReportWorkflow');
        $this->endRun('exec-report', WorkflowRunStatus::Completed);

        $catalog = $this->catalogUnderTest();
        $named = static fn(string $name): WorkflowRunFilter => new WorkflowRunFilter(workflowName: $name);

        self::assertSame(['exec-order'], $this->idsOf($catalog->listRuns(filter: $named('App\\OrderWorkflow'))->runs));
        self::assertSame(['exec-report'], $this->idsOf($catalog->listRuns(WorkflowRunStatus::Completed, filter: $named('App\\ReportWorkflow'))->runs));
        self::assertSame([], $this->idsOf($catalog->listRuns(WorkflowRunStatus::Completed, filter: $named('App\\OrderWorkflow'))->runs), 'both filters apply');
        self::assertSame([], $this->idsOf($catalog->listRuns(filter: $named('App\\Order'))->runs), 'the whole name, not a part of it');
        self::assertSame([], $this->idsOf($catalog->listRuns(filter: $named('app\\orderworkflow'))->runs), 'case counts');
        self::assertSame([], $this->idsOf($catalog->listRuns(filter: $named('App\\"OrderWorkflow'))->runs), 'a quote is a character like any other');
    }

    /**
     * #557: the runs whose execution id starts with what an operator typed, case included.
     */
    public function testFilteringByExecutionIdPrefixReturnsOnlyThoseRuns(): void
    {
        if ($this->refusesFilters()) {
            return;
        }

        $this->startRun('ord-1', 'App\\OrderWorkflow');
        $this->startRun('ord-2', 'App\\OrderWorkflow');
        $this->startRun('rep-1', 'App\\ReportWorkflow');
        $this->endRun('ord-2', WorkflowRunStatus::Completed);

        $catalog = $this->catalogUnderTest();
        $starting = static fn(string $prefix): WorkflowRunFilter => new WorkflowRunFilter(executionIdPrefix: $prefix);

        self::assertSame(['ord-1', 'ord-2'], self::sorted($this->idsOf($catalog->listRuns(filter: $starting('ord-'))->runs)));
        self::assertSame(['ord-2'], $this->idsOf($catalog->listRuns(WorkflowRunStatus::Completed, filter: $starting('ord'))->runs), 'with the status');
        self::assertSame(['rep-1'], $this->idsOf($catalog->listRuns(filter: new WorkflowRunFilter('App\\ReportWorkflow', 'r'))->runs), 'with the name');
        self::assertSame([], $this->idsOf($catalog->listRuns(filter: $starting('ORD'))->runs), 'case counts');
        self::assertSame([], $this->idsOf($catalog->listRuns(filter: $starting('rd-'))->runs), 'the start, not any part');
        self::assertCount(3, $catalog->listRuns(filter: $starting(''))->runs, 'an empty prefix filters nothing');
    }

    /**
     * #557: a SQL LIKE reads `%` and `_` as wildcards, and a prefix is not a pattern.
     */
    public function testAPrefixTakesEveryCharacterLiterally(): void
    {
        if ($this->refusesFilters()) {
            return;
        }

        foreach (['p%1', 'p_1', 'pq1', 'e!1', 'eq1'] as $executionId) {
            $this->startRun($executionId, 'App\\OrderWorkflow');
        }

        $catalog = $this->catalogUnderTest();

        foreach (['p%' => ['p%1'], 'p_' => ['p_1'], 'e!' => ['e!1']] as $prefix => $expected) {
            self::assertSame($expected, $this->idsOf($catalog->listRuns(filter: new WorkflowRunFilter(executionIdPrefix: $prefix))->runs), $prefix);
        }
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
