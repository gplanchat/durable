<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

/**
 * What a workflow class declares, read from its attributes: for a developer reading the workflow, not for the engine.
 */
final readonly class WorkflowDescription
{
    /**
     * @param class-string                   $class
     * @param array<string, WorkflowHandler> $signals signal name => handler
     * @param array<string, WorkflowHandler> $queries query name => handler
     * @param array<string, WorkflowHandler> $updates update name => handler
     */
    public function __construct(
        public string $type,
        public string $class,
        public WorkflowHandler $entry,
        public array $signals,
        public array $queries,
        public array $updates,
    ) {}
}
