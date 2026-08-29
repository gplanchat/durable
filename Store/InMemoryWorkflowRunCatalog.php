<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Store;

use Gplanchat\Durable\Observation\BackendHealth;
use Gplanchat\Durable\Observation\JournalRunHistoryReader;
use Gplanchat\Durable\Observation\WorkflowRunDescription;
use Gplanchat\Durable\Observation\WorkflowRunPage;
use Gplanchat\Durable\Observation\WorkflowRunProjectionInterface;
use Gplanchat\Durable\Observation\WorkflowRunStatus;
use Gplanchat\Durable\Port\WorkflowRunCatalogInterface;

/**
 * Catalogue d'exécutions pour le backend in-memory — le pendant de
 * {@see \Gplanchat\Bridge\Dbal\Store\DbalWorkflowRunCatalog}.
 *
 * Côté SQL, la projection est une table qu'un décorateur alimente à l'écriture, et le catalogue la
 * lit. Ici les deux tiennent dans le même objet : une projection en mémoire n'a ni transaction à
 * partager ni lecture concurrente à servir, et la séparer coûterait une interface et deux
 * décorateurs pour rien. {@see recordStart()} et {@see recordOutcome()} portent donc les mêmes noms
 * que sur la projection DBAL, pour que les deux chemins se lisent pareil.
 *
 * L'historique, lui, n'est pas réimplémenté : {@see JournalRunHistoryReader} lit n'importe quel
 * {@see EventStoreInterface}, et c'est le même code qui sert les deux backends.
 *
 * **Ce qu'il ne peut pas faire, et qu'il dit lui-même.** Un journal in-memory vit et meurt avec le
 * processus. Sous PHP-FPM, la requête qui rend le tableau de bord n'a jamais exécuté le moindre
 * workflow : la liste sera vide, toujours. C'est pourquoi {@see checkHealth()} ne se contente pas
 * de dire « joignable » — son message porte la raison, et le tableau de bord l'affiche. Une liste
 * vide sans explication apprendrait à l'exploitant qu'aucun workflow n'a tourné, ce qui est faux ;
 * c'est le même souci que DUR037 traite pour les faits absents. Sur un worker long — FrankenPHP en
 * mode worker, une commande de consommation — le catalogue voit ce que son processus a exécuté, et
 * c'est là qu'il sert.
 *
 * ponytail: l'ordre est celui des démarrages, index d'insertion en départage, et le curseur est le
 * dernier identifiant rendu. Un catalogue in-memory ne vit que le temps d'un processus ; si un jour
 * il doit survivre à une purge de ses propres lignes, le curseur devient un couple (date, id) comme
 * côté SQL.
 *
 * @see DUR037 l'observation d'un run est une projection
 * @see DUR041 ce catalogue rejoue la suite de conformité du port
 */
final class InMemoryWorkflowRunCatalog implements WorkflowRunCatalogInterface, WorkflowRunProjectionInterface
{
    private const BACKEND = 'in-memory';

    /**
     * @var array<string, array{workflowType: string, status: WorkflowRunStatus, startedAt: \DateTimeImmutable, endedAt: \DateTimeImmutable|null}>
     */
    private array $runs = [];

    public function __construct(
        private readonly EventStoreInterface $events,
    ) {}

    /**
     * Une exécution démarre, ou redémarre sous un autre type après un continue-as-new.
     */
    public function recordStart(string $executionId, string $workflowType): void
    {
        $this->runs[$executionId] = [
            'workflowType' => $workflowType,
            'status' => WorkflowRunStatus::Running,
            'startedAt' => $this->runs[$executionId]['startedAt'] ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            'endedAt' => null,
        ];
    }

    /**
     * L'issue d'une exécution. Une issue sur une exécution jamais démarrée est ignorée : le
     * catalogue décrit ce qu'il a vu commencer, il n'invente pas une ligne à partir d'une fin.
     */
    public function recordOutcome(string $executionId, WorkflowRunStatus $status): void
    {
        if (!isset($this->runs[$executionId])) {
            return;
        }

        $this->runs[$executionId]['status'] = $status;
        $this->runs[$executionId]['endedAt'] = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
    }

    public function listRuns(?WorkflowRunStatus $status = null, ?string $cursor = null, int $limit = 20): WorkflowRunPage
    {
        $ordered = array_reverse(array_keys($this->runs));

        if (null !== $status) {
            $ordered = array_values(array_filter(
                $ordered,
                fn(string $runId): bool => $this->runs[$runId]['status'] === $status,
            ));
        }

        if (null !== $cursor) {
            $position = array_search($cursor, $ordered, true);
            $ordered = false === $position ? [] : \array_slice($ordered, $position + 1);
        }

        $limit = max(1, $limit);
        // Un de plus que demandé : c'est ce qui distingue « la page est pleine » de « il en reste ».
        $window = \array_slice($ordered, 0, $limit + 1);
        $hasMore = \count($window) > $limit;
        $window = \array_slice($window, 0, $limit);

        $runs = array_map(fn(string $runId): WorkflowRunDescription => new WorkflowRunDescription(
            $runId,
            $this->runs[$runId]['workflowType'],
            $this->runs[$runId]['status'],
            $this->runs[$runId]['startedAt'],
            $this->runs[$runId]['endedAt'],
        ), $window);

        return new WorkflowRunPage($runs, $hasMore ? end($window) : null);
    }

    public function readHistory(WorkflowRunDescription $run): array
    {
        return (new JournalRunHistoryReader($this->events))->read($run->runId, $run->workflowName);
    }

    public function checkHealth(): BackendHealth
    {
        return new BackendHealth(
            self::BACKEND,
            true,
            'The in-memory catalog answers, and it only ever sees runs from this process: '
            . 'an empty list means nothing ran here, not that nothing ran. '
            . 'Configure a backend that records outside this process — a SQL database, '
            . 'or a Temporal cluster — to read the runs of every other one.',
            new \DateTimeImmutable('now', new \DateTimeZone('UTC')),
            // Troisième état : il répond, et sa réponse est vide par construction. Une surface a
            // besoin de le lire pour décider quoi afficher ; le dire dans le message ne suffisait
            // pas, et c'est pourquoi deux hôtes sur trois ne le disaient pas.
            ephemeral: true,
        );
    }
}
