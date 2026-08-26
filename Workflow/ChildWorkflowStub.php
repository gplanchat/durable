<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\ChildWorkflowOptions;

/**
 * Proxy de planification côté workflow pour exécuter un workflow enfant typé.
 *
 * Chaque appel à la méthode WorkflowMethod démarre l'enfant et rend un `Awaitable` : c'est
 * l'appelant qui attend, ce qui rend l'enfant composable — une course, un quorum, une
 * échéance. Un stub qui attendait pour l'appelant ne pouvait entrer dans aucun assemblage.
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
        private readonly ChildWorkflowSchedulerInterface $scheduler,
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
    /**
     * @return \Gplanchat\Durable\Awaitable\Awaitable<mixed>
     */
    public function __call(string $name, array $arguments): \Gplanchat\Durable\Awaitable\Awaitable
    {
        if ($name !== $this->workflowMethod->getName()) {
            throw new \BadMethodCallException(\sprintf('Method %s::%s() is not the workflow entry point (expected %s).', $this->workflowClass, $name, $this->workflowMethod->getName()));
        }

        $input = $this->argumentsToInput($arguments);

        return $this->scheduler->startChildWorkflow($this->workflowType, $input, $this->options);
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
