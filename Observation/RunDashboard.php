<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;

/**
 * What the page needs to know, drawn from the port and from nothing else — **for every surface**.
 *
 * This model used to live in the Sylius plugin, and Magento derived its own half of it: backend
 * health on one side, the frieze placed in time on the other, and the same run therefore read
 * differently depending on which application you opened. There is nothing Sylius about it, and
 * there already was nothing — it depends only on the port and on observation facts. It is a
 * **data** contract: a surface that renders no markup serves the same panels.
 *
 * @see DUR049 one projection, several chromes
 *
 * The catalog is nullable, and that is the normal case: the container registers none when no
 * backend is readable. The page then says that no backend is configured — **without naming
 * Temporal**, which may never have been part of the picture on this application.
 *
 * A fact the backend does not have is **absent** from the model, not rendered as an empty string:
 * an empty "task queue" column teaches the operator that the execution has no queue, when in fact
 * it is the backend that has no such notion. A missing key tells nothing false.
 */
final class RunDashboard
{
    public const PAGE_SIZE = 20;

    public function __construct(
        private readonly ?WorkflowRunCatalogInterface $catalog,
    ) {}

    /**
     * @return array{
     *   backend: array<string, mixed>,
     *   runs: list<array<string, mixed>>,
     *   kpis: array<string, int>,
     *   pagination: array{cursor: string|null, nextCursor: string|null, hasNext: bool},
     *   status: string,
     *   selectedRun: array<string, mixed>|null
     * }
     */
    public function build(string $status = 'all', ?string $cursor = null, ?string $selectedRunId = null): array
    {
        if (null === $this->catalog) {
            return [
                'backend' => [
                    'available' => false,
                    'message' => 'No readable durable backend is configured for this application.',
                ],
                'runs' => [],
                'kpis' => self::outcomeCounters([]),
                'pagination' => ['cursor' => $cursor, 'nextCursor' => null, 'hasNext' => false],
                'status' => $status,
                'selectedRun' => null,
            ];
        }

        // "A catalog is registered" and "the backend answers" are two distinct questions. Without
        // this second one, a downed database would give an empty, serene page — the worse of the
        // two possible errors, since the operator concludes there is nothing to see.
        $health = $this->catalog->checkHealth();
        if (!$health->reachable) {
            return [
                'backend' => [
                    'available' => false,
                    'message' => $health->message,
                    'name' => $health->backend,
                    'checkedAt' => $health->checkedAt,
                ],
                'runs' => [],
                'kpis' => self::outcomeCounters([]),
                'pagination' => ['cursor' => $cursor, 'nextCursor' => null, 'hasNext' => false],
                'status' => $status,
                'selectedRun' => null,
            ];
        }

        // A filter coming from a URL is an arbitrary string: ignoring it is worth more than
        // refusing a page to someone who mistyped a link.
        $filter = WorkflowRunStatus::tryFrom($status);
        $page = $this->catalog->listRuns($filter, $cursor, self::PAGE_SIZE);

        $selected = self::pick($page->runs, $selectedRunId);

        return [
            'backend' => [
                'available' => true,
                // The third state: it answers, and its answer is empty by construction because
                // its journal does not survive the process. Empty is then the right answer, not a
                // breakdown — and a surface needs to **read** it in order to say so.
                'ephemeral' => $health->ephemeral,
                'message' => $health->message,
                'name' => $health->backend,
                'checkedAt' => $health->checkedAt,
            ],
            'runs' => array_map(self::describe(...), $page->runs),
            'kpis' => self::outcomeCounters($page->runs),
            'pagination' => [
                'cursor' => $cursor,
                'nextCursor' => $page->nextCursor,
                'hasNext' => null !== $page->nextCursor,
            ],
            'status' => $status,
            'selectedRun' => null === $selected ? null : self::describe($selected) + [
                // The frieze is computed in the core, next to the facts it projects: grouping
                // into actions, placing in time and telling the queue apart from the work are not
                // the host's business, otherwise the same run reads differently from one surface
                // to the next.
                'timeline' => RunTimeline::of($this->catalog->readHistory($selected)),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function describe(WorkflowRunDescription $run): array
    {
        $described = [
            'runId' => $run->runId,
            'workflowName' => $run->workflowName,
            'status' => $run->status->value,
        ];

        // Each fact goes in only if it exists. That is the rule of this whole model.
        if (null !== $run->startedAt) {
            $described['startedAt'] = $run->startedAt;
        }
        if (null !== $run->endedAt) {
            $described['endedAt'] = $run->endedAt;
        }
        if (null !== $run->groupId) {
            $described['groupId'] = $run->groupId;
        }

        return $described;
    }

    /**
     * @param list<WorkflowRunDescription> $runs
     */
    private static function pick(array $runs, ?string $selectedRunId): ?WorkflowRunDescription
    {
        foreach ($runs as $run) {
            if ($run->runId === $selectedRunId) {
                return $run;
            }
        }

        return $runs[0] ?? null;
    }

    /**
     * One counter per outcome, every outcome, over **the set it is given**.
     *
     * Public because a host that paginates differently — Magento's standard grid paginates by
     * offset within a bounded window — counts the same set with the same buckets. This is
     * precisely the hole a hard-coded list digs: the caller who writes its buckets by hand forgets
     * one, and the counters stop adding up without anything saying so.
     *
     * The scope is accepted and must be stated: counting the page is consistent with the
     * requirement that the counters agree with what the list shows, but a "total" label under
     * which you read twenty teaches the operator that an application which has recorded five
     * hundred executions has twenty. The `total` key is therefore the total **of the page**, and
     * the surfaces label it as such.
     *
     * Enumerating the cases rather than writing them by hand avoids the hole a hard-coded list
     * inevitably digs: `continued_as_new` counted in the total and in no bucket, so that an
     * application made of long-running workflows displayed counters that did not add up.
     *
     * @param list<WorkflowRunDescription> $runs
     *
     * @return array<string, int>
     */
    public static function outcomeCounters(array $runs): array
    {
        $kpis = ['total' => \count($runs)];
        foreach (WorkflowRunStatus::cases() as $case) {
            $kpis[$case->value] = 0;
        }

        foreach ($runs as $run) {
            ++$kpis[$run->status->value];
        }

        return $kpis;
    }
}
