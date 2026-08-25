<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Prédicats structurels sur un awaitable, partagés par les points qui décident du réveil.
 */
final class AwaitableInspector
{
    private function __construct()
    {
    }

    /**
     * L'attente porte-t-elle (au moins en partie) sur un minuteur ?
     *
     * Doit traverser les composites : un `any(activity, timer)` attend bien une échéance, et le
     * tester par un simple `instanceof TimerAwaitable` laissait l'exécution sans réveil planifié
     * — elle ne repartait jamais si l'activité n'aboutissait pas.
     *
     * @param Awaitable<mixed> $awaitable
     */
    public static function waitsOnTimer(Awaitable $awaitable): bool
    {
        if ($awaitable instanceof TimerAwaitable) {
            return true;
        }

        if ($awaitable instanceof CancellingAnyAwaitable) {
            return self::waitsOnTimer($awaitable->innerAny());
        }

        if ($awaitable instanceof AnyAwaitable) {
            foreach ($awaitable->members() as $member) {
                if (self::waitsOnTimer($member)) {
                    return true;
                }
            }
        }

        return false;
    }
}
