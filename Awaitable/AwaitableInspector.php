<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Prédicats structurels sur un awaitable, partagés par les points qui décident du réveil.
 */
final class AwaitableInspector
{
    private function __construct() {}

    /**
     * L'attente porte-t-elle (au moins en partie) sur un minuteur ?
     *
     * Doit traverser les composites ({@see CompositeAwaitable}) : un `any(activity, timer)`
     * attend bien une échéance, et le tester par un simple `instanceof TimerAwaitable` laissait
     * l'exécution sans réveil planifié — elle ne repartait jamais si l'activité n'aboutissait
     * pas.
     *
     * @param Awaitable<mixed> $awaitable
     */
    public static function waitsOnTimer(Awaitable $awaitable): bool
    {
        if ($awaitable instanceof TimerAwaitable) {
            return true;
        }

        if ($awaitable instanceof CancellingCompositeAwaitable) {
            return self::waitsOnTimer($awaitable->inner());
        }

        if ($awaitable instanceof CompositeAwaitable) {
            foreach ($awaitable->members() as $member) {
                if (self::waitsOnTimer($member)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Nomme la condition sur laquelle porte l'attente, s'il y en a une.
     *
     * Sert au diagnostic : une exécution qu'aucun message ne peut plus faire avancer doit dire
     * *laquelle* de ses conditions ne peut pas devenir vraie, pas seulement qu'elle est bloquée.
     *
     * @param Awaitable<mixed> $awaitable
     */
    public static function describeCondition(Awaitable $awaitable): ?string
    {
        if ($awaitable instanceof ConditionAwaitable) {
            return $awaitable->describe();
        }

        if ($awaitable instanceof CancellingCompositeAwaitable) {
            return self::describeCondition($awaitable->inner());
        }

        if ($awaitable instanceof CompositeAwaitable) {
            foreach ($awaitable->members() as $member) {
                $described = self::describeCondition($member);
                if (null !== $described) {
                    return $described;
                }
            }
        }

        return null;
    }
}
