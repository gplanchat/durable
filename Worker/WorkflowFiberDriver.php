<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Worker;

use Gplanchat\Durable\ActivityCancellationReason;
use Gplanchat\Durable\Awaitable\ActivityAwaitable;
use Gplanchat\Durable\Awaitable\AnyAwaitable;
use Gplanchat\Durable\Awaitable\Awaitable;
use Gplanchat\Durable\Awaitable\CancellingAnyAwaitable;
use Gplanchat\Durable\Awaitable\TimerAwaitable;
use Gplanchat\Durable\Exception\ContinueAsNewRequested;
use Gplanchat\Durable\Exception\WorkflowCancelledFailure;
use Gplanchat\Durable\ExecutionContext;
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
    public function run(
        string $executionId,
        ExecutionContext $context,
        WorkflowEnvironment $environment,
        callable $handler,
    ): mixed {
        $this->lifecycle->onBeforeRun($executionId);

        $fiber = new \Fiber(static fn () => $handler($environment));

        // Au plus une livraison par exécution du pilote : après avoir relevé l'annulation, le
        // handler peut compenser en attendant de nouvelles opérations, et celles-ci ne doivent
        // pas être annulées à leur tour.
        $cancellationDelivered = false;

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
                // Annulation demandée alors que le fiber attend : la livrer ICI, comme Temporal
                // livre un CanceledFailure, pour que le workflow puisse compenser. L'opération en
                // attente est annulée avec la raison workflow_cancelled, qui sert aussi de trace
                // de livraison — au replay, l'awaitable est rejeté par le journal au même endroit.
                if (!$cancellationDelivered && $this->lifecycle->isCancellationPending($executionId)) {
                    $cancellationDelivered = true;
                    $failure = new WorkflowCancelledFailure($executionId, ActivityCancellationReason::WORKFLOW_CANCELLED);
                    $this->lifecycle->onCancellationDelivered($executionId, self::cancelPending($context, $suspended));

                    try {
                        $suspended = $fiber->throw($failure);
                    } catch (\Throwable $e) {
                        $this->dispatchThrowable($executionId, $e);

                        return null;
                    }

                    continue;
                }

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

        if ($e instanceof WorkflowCancelledFailure) {
            // Le workflow ne l'a pas avalée : l'exécution se termine annulée, pas en échec.
            $this->lifecycle->onCancelled($executionId, $e);

            return;
        }

        $this->lifecycle->onFailed($executionId, $e);
    }

    /**
     * Retire de la file l'opération sur laquelle le fiber attend. Un `any()` en enveloppe
     * plusieurs : toutes les branches encore en attente sont annulées.
     *
     * @param Awaitable<mixed> $pending
     *
     * @return list<string> identifiants des opérations retirées
     */
    private static function cancelPending(ExecutionContext $context, Awaitable $pending): array
    {
        if ($pending instanceof CancellingAnyAwaitable) {
            return self::cancelPending($context, $pending->innerAny());
        }

        if ($pending instanceof AnyAwaitable) {
            $cancelled = [];
            foreach ($pending->members() as $member) {
                foreach (self::cancelPending($context, $member) as $id) {
                    $cancelled[] = $id;
                }
            }

            return $cancelled;
        }

        if ($pending->isSettled()) {
            return [];
        }

        if ($pending instanceof ActivityAwaitable) {
            $context->cancelScheduledActivity($pending->activityId(), ActivityCancellationReason::WORKFLOW_CANCELLED);

            return [$pending->activityId()];
        }

        if ($pending instanceof TimerAwaitable) {
            $context->cancelScheduledTimer($pending->timerId(), ActivityCancellationReason::WORKFLOW_CANCELLED);

            return [$pending->timerId()];
        }

        return [];
    }
}
