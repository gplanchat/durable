<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Worker;

use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Port\WorkflowLifecycleInterface;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Pilote unique du fiber d'un run : démarrage, replay des awaitables déjà réglés, arrêt sur
 * commande nouvelle, terminaison.
 *
 * Cette boucle existait en deux exemplaires — {@see \Gplanchat\Durable\ExecutionEngine} et
 * le runner Temporal — écrits séparément, avec des chaînes de `catch` divergentes : les issues
 * de cycle de vie ajoutées à l'un manquaient à l'autre. Elles passent désormais par
 * {@see WorkflowLifecycleInterface}, dont chaque backend est une implémentation.
 */
final class WorkflowFiberDriver
{
    public function __construct(
        private readonly WorkflowLifecycleInterface $lifecycle,
    ) {
    }

    /**
     * @return mixed Résultat du handler s'il est allé au bout, null sinon (suspension, issue levée
     *               par le port)
     */
    public function run(string $executionId, WorkflowEnvironment $environment, callable $handler): mixed
    {
        $this->lifecycle->onBeforeRun($executionId);

        $fiber = new \Fiber(static fn () => $handler($environment));

        try {
            $suspended = $fiber->start();
        } catch (\Throwable $e) {
            $this->dispatchThrowable($executionId, $e);

            return null;
        }

        while ($fiber->isSuspended()) {
            if (!$suspended instanceof Awaitable) {
                break;
            }

            if (!$suspended->isSettled()) {
                // Commande nouvelle : déjà empilée dans le WorkflowCommandBufferInterface.
                $this->lifecycle->onSuspended($executionId, $suspended);

                return null;
            }

            // Replay : l'awaitable était réglé avant même l'await, on relance tout de suite.
            try {
                $suspended = $fiber->resume();
            } catch (\Throwable $e) {
                $this->dispatchThrowable($executionId, $e);

                return null;
            }
        }

        if ($fiber->isTerminated()) {
            $result = $fiber->getReturn();
            $this->lifecycle->onCompleted($executionId, $result);

            return $result;
        }

        return null;
    }

    private function dispatchThrowable(string $executionId, \Throwable $e): void
    {
        if ($e instanceof ContinueAsNewRequested) {
            $this->lifecycle->onContinuedAsNew($executionId, $e);

            return;
        }

        $this->lifecycle->onFailed($executionId, $e);
    }
}
