<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

/**
 * Un awaitable qui en assemble d'autres.
 *
 * Deux endroits du moteur ont besoin de traverser un assemblage plutôt que de le regarder de
 * l'extérieur : {@see AwaitableInspector::waitsOnTimer()}, qui décide si un réveil doit être
 * planifié, et {@see AwaitableCancellation}, qui doit atteindre les feuilles pour les retirer de
 * la file. Les deux le faisaient par une chaîne de `instanceof` sur les composites connus ; il
 * suffisait d'en ajouter un pour que ses membres cessent silencieusement d'être vus — une
 * exécution sans réveil, ou une activité orpheline. Voir ADR DUR033.
 *
 * @template TValue
 *
 * @extends Awaitable<TValue>
 */
interface CompositeAwaitable extends Awaitable
{
    /**
     * @return list<Awaitable<mixed>>
     */
    public function members(): array;
}
