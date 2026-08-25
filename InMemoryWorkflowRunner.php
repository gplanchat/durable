<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Exception\WorkflowStuckException;
use Gplanchat\Durable\Exception\WorkflowSuspendedException;
use Gplanchat\Durable\Store\EventStoreCommandBuffer;
use Gplanchat\Durable\Store\EventStoreHistorySource;
use Gplanchat\Durable\Store\EventStoreInterface;
use Gplanchat\Durable\Transport\ActivityTransportInterface;

/**
 * Exécute des workflows avec stack in-memory en reproduisant la suspension.
 *
 * Simule le flux distribué : à chaque await() sur une activité non complétée,
 * le workflow suspend, le "worker" exécute les activités de la file, puis
 * le workflow reprend (replay). Permet de tester le comportement de suspension
 * sans processus externes ni Messenger.
 */
final class InMemoryWorkflowRunner
{
    public const DEFAULT_BUDGET_SECONDS = 10.0;

    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityTransportInterface $activityTransport,
        private readonly ActivityExecutor $activityExecutor,
        private readonly int $maxActivityRetries = 0,
        /**
         * Requis pour exécuter des workflows enfants : sans registre, aucun type enfant n'est
         * résoluble et {@see \Gplanchat\Durable\ExecutionContext::executeChildWorkflow()} lève.
         */
        private readonly ?WorkflowRegistry $workflowRegistry = null,
        /**
         * Budget total d'une exécution. Les tentatives d'activité étant illimitées par défaut
         * (sémantique Temporal), un harnais en ligne a besoin d'une borne : sans elle, une
         * activité durablement en échec ferait tourner ce runner sans fin.
         */
        private readonly float $budgetSeconds = self::DEFAULT_BUDGET_SECONDS,
    ) {
    }

    /**
     * Lance un workflow et boucle suspend/resume jusqu'à complétion.
     *
     * @return mixed Résultat du handler
     */
    public function run(string $executionId, callable $handler): mixed
    {
        $runtime = new ExecutionRuntime(
            $this->eventStore,
            $this->activityTransport,
            $this->activityExecutor,
            $this->maxActivityRetries,
            null,
            true, // distributed = true => suspension
        );
        // Le moteur était construit sans runner d'enfant ni coordinateur parent/enfant :
        // un workflow à enfants levait une LogicException et ParentClosePolicy ne cascadait
        // jamais — deux comportements de production absents du harness de test.
        $engine = new ExecutionEngine(
            $this->eventStore,
            $runtime,
            null !== $this->workflowRegistry
                ? new ChildWorkflowRunner(
                    $this->eventStore,
                    $runtime,
                    $this->workflowRegistry,
                    $this->activityExecutor,
                    $this->maxActivityRetries,
                )
                : null,
            new ParentChildWorkflowCoordinator($this->eventStore),
        );

        try {
            return $engine->start($executionId, $handler);
        } catch (WorkflowSuspendedException) {
            // DUR003: expected suspension (control flow), not an error — the while loop runs the worker then resumes.
        }

        $deadline = microtime(true) + $this->budgetSeconds;

        while (true) {
            if (microtime(true) >= $deadline) {
                throw WorkflowStuckException::budgetExhausted($executionId, $this->budgetSeconds);
            }

            $before = $this->eventStore->countEventsInStream($executionId);
            $this->runActivityWorker($executionId, $runtime, max(0.0, $deadline - microtime(true)));

            try {
                return $engine->resume($executionId, $handler);
            } catch (WorkflowSuspendedException) {
                // DUR003: same — suspension until activities have produced the events needed for replay.
            }

            // Un tour qui n'ajoute rien au journal ne peut pas en ajouter au suivant : le
            // workflow attend quelque chose que ce runner ne produira jamais (signal non
            // délivré, update, minuteur lointain). Sans ce garde, la boucle tournait à vide
            // indéfiniment — un test qui oublie de délivrer son signal gelait la suite.
            // ponytail: détection par absence de progrès ; un vrai ordonnanceur de minuteurs
            // demanderait une horloge virtuelle.
            if ($this->eventStore->countEventsInStream($executionId) === $before) {
                // Une tentative encore en file distingue les deux causes : le workflow retente
                // toujours (budget épuisé), plutôt qu'il attend un événement qui ne viendra pas.
                throw null !== $this->activityTransport->nextDueAt()
                    ? WorkflowStuckException::budgetExhausted($executionId, $this->budgetSeconds)
                    : WorkflowStuckException::noProgress($executionId);
            }
        }
    }

    private function runActivityWorker(string $executionId, ExecutionRuntime $runtime, float $budgetSeconds): void
    {
        $context = new ExecutionContext(
            $executionId,
            new EventStoreHistorySource($this->eventStore, $executionId),
            new EventStoreCommandBuffer($this->eventStore, $this->activityTransport, $executionId),
            null,
        );
        $runtime->runUntilIdle($context, $budgetSeconds);
    }
}
