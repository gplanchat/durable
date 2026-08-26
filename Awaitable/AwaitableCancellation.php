<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

use Gplanchat\Durable\ExecutionContext;

/**
 * Retire de la file les opérations encore en attente sous un awaitable.
 *
 * Deux appelants en avaient besoin — l'annulation du workflow, qui vide ce sur quoi le fiber
 * était suspendu, et un composite qui a atteint son verdict et n'a plus rien à faire de ses
 * branches perdantes. Ils en avaient chacun leur version, et elles ne descendaient pas à la même
 * profondeur : celle du composite s'arrêtait au premier niveau, si bien qu'un `all()` borné par
 * une échéance laissait ses activités tourner. Une seule marche, appelée des deux côtés.
 */
final class AwaitableCancellation
{
    private function __construct() {}

    /**
     * @param Awaitable<mixed> $awaitable
     * @param string           $reason une constante de {@see \Gplanchat\Durable\ActivityCancellationReason}
     *
     * @return list<string> identifiants des opérations retirées
     */
    public static function cancelUnsettled(
        ExecutionContext $context,
        Awaitable $awaitable,
        string $reason,
    ): array {
        if ($awaitable instanceof CancellingCompositeAwaitable) {
            return self::cancelUnsettled($context, $awaitable->inner(), $reason);
        }

        if ($awaitable instanceof CompositeAwaitable) {
            $cancelled = [];
            foreach ($awaitable->members() as $member) {
                foreach (self::cancelUnsettled($context, $member, $reason) as $id) {
                    $cancelled[] = $id;
                }
            }

            return $cancelled;
        }

        if ($awaitable->isSettled()) {
            return [];
        }

        if ($awaitable instanceof ActivityAwaitable) {
            $context->cancelScheduledActivity($awaitable->activityId(), $reason);

            return [$awaitable->activityId()];
        }

        if ($awaitable instanceof TimerAwaitable) {
            $context->cancelScheduledTimer($awaitable->timerId(), $reason);

            return [$awaitable->timerId()];
        }

        if ($awaitable instanceof NexusOperationAwaitable) {
            $context->cancelScheduledNexusOperation($awaitable->operationId(), $reason);

            return [$awaitable->operationId()];
        }

        return [];
    }
}
