<?php

declare(strict_types=1);

namespace Gplanchat\Durable;

use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * Registre des workflows par type.
 *
 * Chaque classe enregistrée est indexée **deux fois** : par l’**alias** Temporal
 * ({@see WorkflowDefinitionLoader::workflowTypeForClass()} — argument `#[Workflow]` ou nom court)
 * et par le **FQCN**, pour le dispatch PHP. Le journal et Temporal utilisent l’**alias** uniquement
 * (voir {@see WorkflowDefinitionLoader::aliasForTemporalInterop()}).
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
        $factory = $definition['factory'];
        $alias = $definition['workflowType'];

        $this->factories[$alias] = $factory;
        $this->factories[$workflowClass] = $factory;
    }

    /**
     * Enregistre une factory inline (pour les tests ou l'enregistrement programmatique).
     *
     * La factory reçoit le payload de démarrage et retourne un callable(WorkflowEnvironment): mixed.
     *
     * @param callable(array<string, mixed>): callable(WorkflowEnvironment): mixed $factory
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
