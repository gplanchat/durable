<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\ChildWorkflowOptions;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Proxy de planification côté workflow pour exécuter un workflow enfant typé.
 *
 * Chaque appel à la méthode WorkflowMethod délègue à WorkflowEnvironment::executeChildWorkflow.
 *
 * @template TWorkflow of object
 */
final class ChildWorkflowStub
{
    private string $workflowType;

    private \ReflectionMethod $workflowMethod;

    /**
     * @param class-string<TWorkflow> $workflowClass
     */
    public function __construct(
        private readonly WorkflowEnvironment $environment,
        private readonly string $workflowClass,
        WorkflowDefinitionLoader $loader,
        private readonly ?ChildWorkflowOptions $options = null,
    ) {
        $metadata = $loader->resolveChildWorkflowMetadata($workflowClass);
        $this->workflowType = $metadata['workflowType'];
        $this->workflowMethod = $metadata['workflowMethod'];
    }

    /**
     * @param array<int, mixed> $arguments
     */
    public function __call(string $name, array $arguments): mixed
    {
        if ($name !== $this->workflowMethod->getName()) {
            throw new \BadMethodCallException(\sprintf('Method %s::%s() is not the workflow entry point (expected %s).', $this->workflowClass, $name, $this->workflowMethod->getName()));
        }

        $input = $this->argumentsToInput($arguments);

        return $this->environment->executeChildWorkflow($this->workflowType, $input, $this->options);
    }

    /**
     * @param array<int, mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function argumentsToInput(array $arguments): array
    {
        $params = $this->workflowMethod->getParameters();
        $input = [];
        foreach ($params as $i => $param) {
            $key = $param->getName();
            $input[$key] = $arguments[$i] ?? ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
        }

        return $input;
    }
}
