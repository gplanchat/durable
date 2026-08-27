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
 * Suite de conformité de {@see WorkflowRunCatalogInterface} — DUR041.
 *
 * Ce port est en **lecture seule** : la suite ne peut donc pas se remplir elle-même, et demande
 * deux crochets d'amorçage à l'adaptateur. C'est la forme qu'une suite de conformité prend quand le
 * port n'a pas d'écriture — les crochets disent « fais exister une exécution dans cet état », pas
 * « écris cette ligne ».
 *
 * **Ce que la suite n'exige pas :** un ordre précis entre deux exécutions démarrées dans la même
 * seconde. Le contrat annonce « de la plus récemment démarrée à la plus ancienne », et un backend
 * qui date à la seconde n'a alors pas de départage neutre à offrir. Exiger un ordre que le port ne
 * promet pas ferait échouer un adaptateur correct, ce qui est la façon la plus sûre de rendre une
 * suite inutilisable. Ce qui est exigé, et qui est la vraie garantie d'un tableau de bord, c'est
 * qu'une pagination **ne perde rien et ne montre rien deux fois**.
 *
 * @see DUR041
 * @see DUR037
 */
abstract class WorkflowRunCatalogConformanceTestCase extends TestCase
{
    /**
     * Le catalogue sous test, au-dessus du stockage que les deux crochets remplissent.
     */
    abstract protected function catalogUnderTest(): WorkflowRunCatalogInterface;

    /**
     * Fait exister une exécution en cours, portant ce type de workflow.
     */
    abstract protected function startRun(string $executionId, string $workflowType): void;

    /**
     * Amène une exécution déjà démarrée à son issue.
     */
    abstract protected function endRun(string $executionId, WorkflowRunStatus $outcome): void;

    // -----------------------------------------------------------------------------------------

    public function testAnEmptyCatalogListsNothing(): void
    {
        $page = $this->catalogUnderTest()->listRuns();

        self::assertSame([], $page->runs);
        self::assertNull($page->nextCursor, 'rien à lire ensuite');
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
     * La garantie qui compte pour une vue. Les exécutions sont créées d'affilée, donc très
     * probablement dans la même seconde : c'est exactement le cas où un curseur à décalage fait
     * glisser la fenêtre.
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
                self::assertGreaterThan($previous, $event->sequence, 'les séquences doivent croître');
            }
            $previous = $event->sequence;
        }
    }

    /**
     * « Ne lève jamais » est dans le contrat : une sonde en échec est un diagnostic, pas une panne
     * de l'appelant.
     */
    public function testCheckingHealthAnswersRatherThanThrows(): void
    {
        $health = $this->catalogUnderTest()->checkHealth();

        self::assertNotSame('', $health->backend, 'une santé doit dire de quel backend elle parle');
        self::assertNotSame('', $health->message);
        self::assertTrue($health->reachable, 'le stockage du test est joignable par construction');
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
                self::assertNotContains($id, $seen, \sprintf('%s est apparu deux fois en paginant', $id));
                $seen[] = $id;
            }
            $cursor = $page->nextCursor;
        } while (null !== $cursor && ++$guard < 50);

        self::assertLessThan(50, $guard, 'la pagination ne se termine pas');

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
