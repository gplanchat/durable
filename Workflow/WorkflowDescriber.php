<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Attribute\AsQueryMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsUpdateMethod;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\Exception\WorkflowHasNoEntryPoint;

/**
 * Reads a workflow class into a {@see WorkflowDescription}, with the same lookups as {@see WorkflowDefinitionLoader}.
 */
final class WorkflowDescriber
{
    private function __construct() {}

    /**
     * @param class-string $workflowClass
     */
    public static function describe(string $workflowClass): WorkflowDescription
    {
        $reflection = new \ReflectionClass($workflowClass);
        $entry = null;
        $signals = $queries = $updates = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }
            if ([] !== $method->getAttributes(AsWorkflowMethod::class) && $method->getDeclaringClass()->getName() === $workflowClass) {
                $entry = WorkflowHandler::fromMethod($method, injectedHidden: true);
            }
            foreach ($method->getAttributes(AsSignalMethod::class) as $attribute) {
                $signals[$attribute->newInstance()->signalName()] = WorkflowHandler::fromMethod($method);
            }
            foreach ($method->getAttributes(AsQueryMethod::class) as $attribute) {
                $queries[$attribute->newInstance()->name] = WorkflowHandler::fromMethod($method);
            }
            foreach ($method->getAttributes(AsUpdateMethod::class) as $attribute) {
                $updates[$attribute->newInstance()->updateName()] = WorkflowHandler::fromMethod($method);
            }
        }

        // The loader refuses a class without exactly one entry point: a registered class has one.
        return new WorkflowDescription(
            (new WorkflowDefinitionLoader())->workflowTypeForClass($workflowClass),
            $workflowClass,
            $entry ?? throw WorkflowHasNoEntryPoint::forClass($workflowClass),
            $signals,
            $queries,
            $updates,
        );
    }
}
