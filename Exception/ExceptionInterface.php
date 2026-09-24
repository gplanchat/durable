<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Exception;

/**
 * Every error Durable throws, catchable as one (#330), as with any Symfony component.
 *
 * The control-flow signals — {@see WorkflowSuspendedException}, {@see ContinueAsNewRequested},
 * {@see ChildWorkflowStartDeferred} — do not implement it: they end a pass of workflow code on
 * purpose, and a `catch (ExceptionInterface)` in that code must not swallow them.
 */
interface ExceptionInterface extends \Throwable {}
