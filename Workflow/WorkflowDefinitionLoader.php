<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Attribute\AsQueryMethod;
use Gplanchat\Durable\Attribute\AsSignalMethod;
use Gplanchat\Durable\Attribute\AsUpdateMethod;
use Gplanchat\Durable\Attribute\AsWorkflow;
use Gplanchat\Durable\Attribute\AsWorkflowMethod;
use Gplanchat\Durable\WorkflowEnvironment;

/**
 * Loads a workflow definition from a class carrying #[AsWorkflow] and #[AsWorkflowMethod] attributes.
 *
 * Produces a factory compatible with WorkflowRegistry.
 */
final class WorkflowDefinitionLoader
{
    /**
     * Resolves the metadata for a child workflow stub (type + entry method).
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
     * Name registered in {@see WorkflowRegistry}: value of {@see AsWorkflow} (1st argument) if present, otherwise {@see \ReflectionClass::getShortName()}.
     *
     * @param class-string $workflowClass
     */
    public function workflowTypeForClass(string $workflowClass): string
    {
        return $this->resolveWorkflowType(new \ReflectionClass($workflowClass));
    }

    /**
     * Name for Temporal's use (server-side workflow type) and for the journal: **never the FQCN**.
     * If the string is an existing {@code class-string}, resolves as {@see workflowTypeForClass};
     * otherwise the value is already an alias and is returned as is.
     */
    public function aliasForTemporalInterop(string $workflowTypeOrFqcn): string
    {
        if (class_exists($workflowTypeOrFqcn)) {
            return $this->workflowTypeForClass($workflowTypeOrFqcn);
        }

        return $workflowTypeOrFqcn;
    }

    /**
     * Produces workflowType and factory for a workflow class.
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
            return function (WorkflowEnvironment $env, ?QueryHandlerRegistry $queries = null) use ($workflowClass, $method, $input): mixed {
                $instance = $this->instantiate($workflowClass, $env);
                $this->registerQueryHandlers($workflowClass, $instance, $queries ?? new QueryHandlerRegistry());
                $this->registerSignalHandlers($workflowClass, $instance, $env);
                $this->registerUpdateHandlers($workflowClass, $instance, $env);

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
        $attrs = $reflection->getAttributes(AsWorkflow::class);
        if ([] !== $attrs) {
            return $attrs[0]->newInstance()->name;
        }

        return $reflection->getShortName();
    }

    /**
     * @param \ReflectionClass<object> $reflection
     */
    /**
     * The workflow method's parameters, in order, with what makes them optional.
     *
     * Public because a workflow's input is **keyed by name**: whoever builds that payload
     * elsewhere — a Nexus operation fulfilled by this workflow, for instance — needs to know which
     * names to write, and should not have to redo the `#[AsWorkflowMethod]` lookup to learn them.
     *
     * @param class-string $workflowClass
     *
     * @return array<string, bool> parameter name => has a default value
     */
    public function workflowMethodParameters(string $workflowClass): array
    {
        $parameters = [];
        foreach ($this->resolveWorkflowMethod(new \ReflectionClass($workflowClass))->getParameters() as $parameter) {
            $parameters[$parameter->getName()] = $parameter->isDefaultValueAvailable();
        }

        return $parameters;
    }

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
            $attrs = $method->getAttributes(AsWorkflowMethod::class);
            if ([] !== $attrs) {
                $workflowMethods[] = $method;
            }
        }

        if (1 !== \count($workflowMethods)) {
            throw new \InvalidArgumentException(\sprintf('AsWorkflow class %s must have exactly one #[AsWorkflowMethod], found %d', $reflection->getName(), \count($workflowMethods)));
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
                throw new \InvalidArgumentException(\sprintf('AsWorkflow %s constructor parameter $%s must have a default or be WorkflowEnvironment', $workflowClass, $param->getName()));
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

    /**
     * Scans the workflow class for #[AsSignalMethod] attributes and registers them on WorkflowEnvironment.
     *
     * Same translation as for queries: the attribute is the declarative form of
     * {@see WorkflowEnvironment::onSignal()}, and both produce the same dispatch.
     *
     * @param class-string $workflowClass
     */
    private function registerSignalHandlers(string $workflowClass, object $instance, WorkflowEnvironment $env): void
    {
        $reflection = new \ReflectionClass($workflowClass);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attrs = $method->getAttributes(AsSignalMethod::class);
            if ($attrs === []) {
                continue;
            }
            $env->onSignal($attrs[0]->newInstance()->signalName(), static fn(mixed ...$args) => $method->invoke($instance, ...$args));
        }
    }

    /**
     * Scans the workflow class for #[AsUpdateMethod] attributes and registers them on WorkflowEnvironment.
     *
     * @param class-string $workflowClass
     */
    private function registerUpdateHandlers(string $workflowClass, object $instance, WorkflowEnvironment $env): void
    {
        $reflection = new \ReflectionClass($workflowClass);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attrs = $method->getAttributes(AsUpdateMethod::class);
            if ($attrs === []) {
                continue;
            }
            $env->onUpdate($attrs[0]->newInstance()->updateName(), static fn(mixed ...$args): mixed => $method->invoke($instance, ...$args));
        }
    }

    /**
     * Scans the workflow class for #[AsQueryMethod] attributes and registers them on WorkflowEnvironment.
     *
     * @param class-string $workflowClass
     */
    private function registerQueryHandlers(string $workflowClass, object $instance, QueryHandlerRegistry $queries): void
    {
        $reflection = new \ReflectionClass($workflowClass);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attrs = $method->getAttributes(AsQueryMethod::class);
            if ($attrs === []) {
                continue;
            }
            $attr = $attrs[0]->newInstance();
            $queryType = $attr->name;
            $queries->register($queryType, static fn(mixed ...$args) => $method->invoke($instance, ...$args));
        }
    }
}
