<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;

/**
 * Ce que la page a besoin de savoir, tiré du port et de rien d'autre — **pour toutes les surfaces**.
 *
 * Ce modèle vivait dans le greffon Sylius, et Magento en dérivait sa propre moitié : la santé du
 * backend d'un côté, la frise placée dans le temps de l'autre, et le même run se lisait donc
 * différemment selon l'application ouverte. Il n'a rien de Sylius, et n'en avait déjà rien — il ne
 * dépend que du port et des faits d'observation. C'est un contrat de **données** : une surface qui
 * ne rend aucun balisage sert les mêmes panneaux.
 *
 * @see DUR048 une projection, plusieurs habillages
 *
 * Le catalogue est nullable, et c'est le cas normal : le conteneur n'en enregistre aucun quand
 * aucun backend n'est lisible. La page dit alors qu'aucun backend n'est configuré — **sans nommer
 * Temporal**, qui peut n'avoir jamais été de la partie sur cette application.
 *
 * Un fait que le backend n'a pas est **absent** du modèle, pas rendu en chaîne vide : une colonne
 * « file de tâches » vide apprend à l'exploitant que l'exécution n'a pas de file, alors que c'est le
 * backend qui n'a pas la notion. Une clé absente ne raconte rien de faux.
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

        // « Un catalogue est enregistré » et « le backend répond » sont deux questions distinctes.
        // Sans cette seconde, une base tombée donnerait une page vide et sereine — la pire des deux
        // erreurs possibles, puisque l'exploitant en conclut qu'il n'y a rien à voir.
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

        // Un filtre venu d'une URL est une chaîne quelconque : l'ignorer vaut mieux que refuser une
        // page à quelqu'un qui a mal recopié un lien.
        $filter = WorkflowRunStatus::tryFrom($status);
        $page = $this->catalog->listRuns($filter, $cursor, self::PAGE_SIZE);

        $selected = self::pick($page->runs, $selectedRunId);

        return [
            'backend' => [
                'available' => true,
                // Le troisième état : il répond, et sa réponse est vide par construction parce que
                // son journal ne survit pas au processus. Vide est alors la bonne réponse, pas une
                // panne — et une surface a besoin de le **lire** pour le dire.
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
                // La frise se calcule dans le cœur, à côté des faits qu'elle projette : grouper en
                // actions, placer dans le temps et distinguer la file du travail ne sont pas
                // l'affaire de l'hôte, sans quoi le même run se lit différemment selon la surface.
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

        // Chaque fait n'entre que s'il existe. C'est la règle de tout ce modèle.
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
     * Un compteur par issue, toutes les issues, sur **l'ensemble qu'on lui donne**.
     *
     * Publique parce qu'un hôte qui pagine autrement — la grille standard de Magento pagine par
     * décalage dans une fenêtre bornée — compte le même ensemble avec les mêmes seaux. C'est
     * précisément le trou qu'une liste figée creuse : l'appelant qui écrit ses seaux à la main en
     * oublie un, et les compteurs cessent de s'additionner sans que rien ne le dise.
     *
     * La portée est assumée et doit être dite : compter la page est cohérent avec l'exigence que
     * les compteurs concordent avec ce que la liste montre, mais un intitulé « total » sous lequel
     * on lit vingt apprend à l'exploitant qu'une application qui a enregistré cinq cents exécutions
     * en a vingt. La clé `total` est donc le total **de la page**, et les surfaces l'intitulent
     * ainsi.
     *
     * Énumérer les cas plutôt que les écrire à la main évite le trou qu'une liste figée creuse
     * fatalement : `continued_as_new` comptait dans le total et dans aucun seau, si bien qu'une
     * application faite de workflows longs affichait des compteurs qui ne s'additionnaient pas.
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
