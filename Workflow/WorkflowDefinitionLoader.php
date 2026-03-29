<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Attribute\Workflow;
use Gplanchat\Durable\Attribute\WorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Charge une définition de workflow depuis une classe avec attributs #[Workflow] et #[WorkflowMethod].
 *
 * Produit une factory compatible avec WorkflowRegistry.
 */
final class WorkflowDefinitionLoader
{
    /**
     * Résout les métadonnées pour un child workflow stub (type + méthode d'entrée).
     *
     * @param class-string $workflowClass
     *
     * @return array{workflowType: string, workflowMethod: \ReflectionMethod}
     */
    public function resolveChildWorkflowMetadata(string $workflowClass): array
    {
        $reflection = new \ReflectionClass($workflowClass);

        return [
            'workflowType' => $this->resolveWorkflowType($reflection),
            'workflowMethod' => $this->resolveWorkflowMethod($reflection),
        ];
    }

    /**
     * Produit workflowType et factory pour une classe workflow.
     *
     * @param class-string $workflowClass
     *
     * @return array{workflowType: string, factory: callable}
     */
    public function load(string $workflowClass): array
    {
        $reflection = new \ReflectionClass($workflowClass);
        $workflowType = $this->resolveWorkflowType($reflection);
        $method = $this->resolveWorkflowMethod($reflection);

        $factory = function (array $input) use ($workflowClass, $method): callable {
            return function (WorkflowEnvironment $env) use ($workflowClass, $method, $input): mixed {
                $instance = $this->instantiate($workflowClass, $env);

                return $method->invokeArgs($instance, $this->mapInputToArguments($method, $input));
            };
        };

        return ['workflowType' => $workflowType, 'factory' => $factory];
    }

    /**
     * @param \ReflectionClass<object> $reflection
     */
    private function resolveWorkflowType(\ReflectionClass $reflection): string
    {
        $attrs = $reflection->getAttributes(Workflow::class);
        if ([] !== $attrs) {
            return $attrs[0]->newInstance()->name;
        }

        return $reflection->getShortName();
    }

    /**
     * @param \ReflectionClass<object> $reflection
     */
    private function resolveWorkflowMethod(\ReflectionClass $reflection): \ReflectionMethod
    {
        $workflowMethods = [];
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }
            if ($method->getDeclaringClass()->getName() !== $reflection->getName()) {
                continue;
            }
            $attrs = $method->getAttributes(WorkflowMethod::class);
            if ([] !== $attrs) {
                $workflowMethods[] = $method;
            }
        }

        if (1 !== \count($workflowMethods)) {
            throw new \InvalidArgumentException(\sprintf('Workflow class %s must have exactly one #[WorkflowMethod], found %d', $reflection->getName(), \count($workflowMethods)));
        }

        return $workflowMethods[0];
    }

    /**
     * @param class-string $workflowClass
     */
    private function instantiate(string $workflowClass, WorkflowEnvironment $env): object
    {
        $reflection = new \ReflectionClass($workflowClass);
        $constructor = $reflection->getConstructor();

        if (null === $constructor) {
            return new $workflowClass();
        }

        $params = $constructor->getParameters();
        $args = [];
        foreach ($params as $param) {
            $type = $param->getType();
            if ($type instanceof \ReflectionNamedType && WorkflowEnvironment::class === $type->getName()) {
                $args[] = $env;
            } elseif ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
            } else {
                throw new \InvalidArgumentException(\sprintf('Workflow %s constructor parameter $%s must have a default or be WorkflowEnvironment', $workflowClass, $param->getName()));
            }
        }

        return $reflection->newInstanceArgs($args);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<int, mixed>
     */
    private function mapInputToArguments(\ReflectionMethod $method, array $input): array
    {
        $params = $method->getParameters();
        if (1 === \count($params)) {
            $param = $params[0];
            if ($param->getType() instanceof \ReflectionNamedType
                && 'array' === $param->getType()->getName()
                && \in_array($param->getName(), ['input', 'payload'], true)) {
                return [$input];
            }
        }

        $args = [];
        foreach ($params as $param) {
            $key = $param->getName();
            $args[] = \array_key_exists($key, $input) ? $input[$key] : ($param->isDefaultValueAvailable() ? $param->getDefaultValue() : null);
        }

        return $args;
    }
}
