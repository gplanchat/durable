<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Bundle\Messenger\TimerWakeDelayCalculator;
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
        // Horloge virtuelle : un harnais en ligne n'a personne pour livrer un réveil de
        // minuteur, et attendre une échéance pour de vrai rendrait intestable tout workflow qui
        // dort. Elle n'avance que d'échéance en échéance, jamais toute seule.
        // Un objet, pas une variable : une fonction fléchée capture par valeur, l'horloge ne
        // bougerait jamais.
        $clock = new class {
            public float $now;
        };
        $clock->now = microtime(true);

        $runtime = new ExecutionRuntime(
            $this->eventStore,
            $this->activityTransport,
            $this->activityExecutor,
            $this->maxActivityRetries,
            static fn (): float => $clock->now,
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
            // Les minuteurs déjà échus partent à chaque tour ; le temps, lui, ne bouge pas encore.
            $runtime->checkTimers($this->timerContext($executionId, $runtime));

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
                // Plus rien ne bouge : c'est seulement maintenant qu'on a le droit d'avancer le
                // temps. Le faire plus tôt ferait gagner le minuteur d'une course que l'activité
                // était en train de remporter.
                if ($this->skipToNextTimer($executionId, $runtime, $clock)) {
                    continue;
                }

                // Une tentative encore en file distingue les deux causes : le workflow retente
                // toujours (budget épuisé), plutôt qu'il attend un événement qui ne viendra pas.
                throw null !== $this->activityTransport->nextDueAt()
                    ? WorkflowStuckException::budgetExhausted($executionId, $this->budgetSeconds)
                    : WorkflowStuckException::noProgress($executionId);
            }
        }
    }

    /**
     * Avance l'horloge virtuelle jusqu'à la prochaine échéance et fait partir le minuteur.
     *
     * C'est ce qui rend `sleep(3600)` testable en une milliseconde, sans consommer de temps réel.
     * En production le worker fait l'inverse : il attend le réveil que lui planifie
     * {@see \Gplanchat\Durable\Bundle\Messenger\TimerWakeDelayCalculator}.
     *
     * N'est appelé que lorsque plus rien d'autre ne progresse — sauter le temps tant qu'une
     * activité peut encore aboutir ferait gagner le minuteur de tout `any(activité, minuteur)`.
     *
     * @return bool true si le temps a été avancé
     */
    private function skipToNextTimer(string $executionId, ExecutionRuntime $runtime, object $clock): bool
    {
        $dueInMs = TimerWakeDelayCalculator::millisecondsUntilNextTimerDue($this->eventStore, $executionId, $clock->now);
        if (null === $dueInMs) {
            return false;
        }

        $clock->now += max(0.0, (float) $dueInMs / 1000.0);
        $runtime->checkTimers($this->timerContext($executionId, $runtime));

        return true;
    }

    private function timerContext(string $executionId, ExecutionRuntime $runtime): ExecutionContext
    {
        return new ExecutionContext(
            $executionId,
            new EventStoreHistorySource($this->eventStore, $executionId),
            new EventStoreCommandBuffer($this->eventStore, $this->activityTransport, $executionId, $runtime->nowSeconds(...)),
        );
    }

    private function runActivityWorker(string $executionId, ExecutionRuntime $runtime, float $budgetSeconds): void
    {
        $context = new ExecutionContext(
            $executionId,
            new EventStoreHistorySource($this->eventStore, $executionId),
            new EventStoreCommandBuffer($this->eventStore, $this->activityTransport, $executionId, $runtime->nowSeconds(...)),
            null,
        );
        $runtime->runUntilIdle($context, $budgetSeconds);
    }
}
