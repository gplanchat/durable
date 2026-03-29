<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionRuntime;

/**
 * Awaitable lié à une exécution : expose le couple (ExecutionContext, ExecutionRuntime).
 *
 * Les awaitables produits par le moteur (activité, timer, etc.) peuvent implémenter
 * cette interface pour que les composites (ex. CancellingAnyAwaitable) récupèrent
 * le contexte depuis leurs membres. Aujourd’hui, WorkflowEnvironment fournit le
 * contexte au niveau de l’appel.
 *
 * @extends Awaitable<mixed>
 */
interface ExecutionBoundAwaitable extends Awaitable
{
    public function executionContext(): ExecutionContext;

    public function executionRuntime(): ExecutionRuntime;
}
