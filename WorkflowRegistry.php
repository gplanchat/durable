<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * Registry of workflows by type.
 *
 * Every registered class is indexed **twice**: by the Temporal **alias**
 * ({@see WorkflowDefinitionLoader::workflowTypeForClass()} — `#[AsWorkflow]` argument or short name)
 * and by the **FQCN**, for PHP dispatch. The log and Temporal use the **alias** only
 * (see {@see WorkflowDefinitionLoader::aliasForTemporalInterop()}).
 *
 * Factories receive the payload and return a callable(WorkflowEnvironment): mixed.
 *
 * @see DUR021 Symfony Messenger integration (distributed resume)
 */
final class WorkflowRegistry
{
    /** @var array<string, callable> */
    private array $factories = [];

    public function __construct(
        private readonly ?WorkflowDefinitionLoader $workflowLoader = null,
    ) {}

    /**
     * Registers a workflow class carrying #[AsWorkflow] and #[AsWorkflowMethod].
     *
     * @param class-string $workflowClass
     */
    public function registerClass(string $workflowClass): void
    {
        $loader = $this->workflowLoader ?? new WorkflowDefinitionLoader();
        $definition = $loader->load($workflowClass);
        $factory = $definition['factory'];
        $alias = $definition['workflowType'];

        $this->factories[$alias] = $factory;
        $this->factories[$workflowClass] = $factory;
    }

    /**
     * Registers an inline factory (for tests or programmatic registration).
     *
     * The factory receives the start payload and returns a callable(WorkflowEnvironment): mixed.
     *
     * @param callable(array<string, mixed>): (callable(WorkflowEnvironment): mixed) $factory
     */
    public function registerFactory(string $workflowType, callable $factory): void
    {
        $this->factories[$workflowType] = $factory;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return callable(WorkflowEnvironment): mixed
     */
    public function getHandler(string $workflowType, array $payload): callable
    {
        $factory = $this->factories[$workflowType] ?? null;
        if (null === $factory) {
            throw new \InvalidArgumentException(\sprintf('Unknown workflow type: %s', $workflowType));
        }

        return $factory($payload);
    }

    public function has(string $workflowType): bool
    {
        return isset($this->factories[$workflowType]);
    }
}
