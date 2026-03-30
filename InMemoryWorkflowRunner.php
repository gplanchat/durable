<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Exception\WorkflowSuspendedException;
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
    public function __construct(
        private readonly EventStoreInterface $eventStore,
        private readonly ActivityTransportInterface $activityTransport,
        private readonly ActivityExecutor $activityExecutor,
        private readonly int $maxActivityRetries = 0,
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
        $engine = new ExecutionEngine($this->eventStore, $runtime);

        try {
            return $engine->start($executionId, $handler);
        } catch (WorkflowSuspendedException) {
            // ADR018: suspension attendue (contrôle de flux), pas une erreur — la boucle while exécute le worker puis resume().
        }

        while (true) {
            $this->runActivityWorker($executionId, $runtime);

            try {
                return $engine->resume($executionId, $handler);
            } catch (WorkflowSuspendedException) {
                // ADR018: idem — suspension attendue jusqu’à ce que les activités aient produit les événements nécessaires au replay.
            }
        }
    }

    private function runActivityWorker(string $executionId, ExecutionRuntime $runtime): void
    {
        $context = new ExecutionContext(
            $executionId,
            $this->eventStore,
            $this->activityTransport,
            null,
        );
        $runtime->runUntilIdle($context);
    }
}
