<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Awaitable;

use Gplanchat\Durable\ExecutionContext;
use Gplanchat\Durable\ExecutionRuntime;

/**
 * An awaitable bound to an execution: exposes the (ExecutionContext, ExecutionRuntime) pair.
 *
 * The awaitables the engine produces (activity, timer, etc.) may implement this interface so
 * that composites (e.g. CancellingCompositeAwaitable) recover the context from their members.
 * Today, WorkflowEnvironment supplies the context at the call level.
 *
 * @extends Awaitable<mixed>
 */
interface ExecutionBoundAwaitable extends Awaitable
{
    public function executionContext(): ExecutionContext;

    public function executionRuntime(): ExecutionRuntime;
}
