<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * Registre des workflows par type.
 *
 * Les factories reçoivent le payload et retournent un callable(WorkflowEnvironment): mixed.
 *
 * @see ADR009 Modèle distribué et re-dispatch
 */
final class WorkflowRegistry
{
    /** @var array<string, callable> */
    private array $factories = [];

    public function __construct(
        private readonly ?WorkflowDefinitionLoader $workflowLoader = null,
    ) {
    }

    /**
     * Enregistre une classe workflow avec #[Workflow] et #[WorkflowMethod].
     *
     * @param class-string $workflowClass
     */
    public function registerClass(string $workflowClass): void
    {
        $loader = $this->workflowLoader ?? new WorkflowDefinitionLoader();
        $definition = $loader->load($workflowClass);

        $this->factories[$definition['workflowType']] = $definition['factory'];
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
