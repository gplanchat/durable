<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

use Gplanchat\Durable\Activity\ActivityStub;
use Gplanchat\Durable\Attribute\Activities;
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
        // Read here, once: a replay only runs the plan.
        $arguments = $this->planArguments($method);

        $factory = function (array $input) use ($workflowClass, $method, $arguments): callable {
            return function (WorkflowEnvironment $env, ?QueryHandlerRegistry $queries = null) use ($workflowClass, $method, $arguments, $input): mixed {
                $instance = $this->instantiate($workflowClass, $env);
                $this->registerQueryHandlers($workflowClass, $instance, $queries ?? new QueryHandlerRegistry());
                $this->registerSignalHandlers($workflowClass, $instance, $env);
                $this->registerUpdateHandlers($workflowClass, $instance, $env);

                return $method->invokeArgs($instance, array_map(static fn(\Closure $argument): mixed => $argument($env, $input), $arguments));
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
     * Whether the loader supplies this parameter itself rather than reading it from the input: the
     * environment, and the activity stubs. Every reader of a workflow method's signature asks this
     * one question, so the input never counts a parameter the caller cannot pass.
     */
    public static function isInjected(\ReflectionParameter $parameter): bool
    {
        $type = $parameter->getType();

        return [] !== $parameter->getAttributes(Activities::class)
            || ($type instanceof \ReflectionNamedType && \in_array($type->getName(), [WorkflowEnvironment::class, ActivityStub::class], true));
    }

    /**
     * One closure per parameter, in order, that produces its argument from the environment and the
     * input.
     *
     * @return list<\Closure(WorkflowEnvironment, array<string, mixed>): mixed>
     */
    private function planArguments(\ReflectionMethod $method): array
    {
        $inputs = array_values(array_filter($method->getParameters(), static fn(\ReflectionParameter $p): bool => !self::isInjected($p)));
        // `run(array $input)` receives the whole input; injected parameters beside it do not change that.
        $wholeInput = 1 === \count($inputs)
            && $inputs[0]->getType() instanceof \ReflectionNamedType
            && 'array' === $inputs[0]->getType()->getName()
            && \in_array($inputs[0]->getName(), ['input', 'payload'], true);

        $plan = [];
        foreach ($method->getParameters() as $param) {
            $type = $param->getType();
            $typeName = $type instanceof \ReflectionNamedType ? $type->getName() : (string) $type;
            $attributes = $param->getAttributes(Activities::class);

            if ([] !== $attributes) {
                $contract = $attributes[0]->newInstance()->contract;
                $plan[] = static fn(WorkflowEnvironment $env): ActivityStub => $env->activityStub($contract);
            } elseif (WorkflowEnvironment::class === $typeName) {
                $plan[] = static fn(WorkflowEnvironment $env): WorkflowEnvironment => $env;
            } elseif ($wholeInput) {
                $plan[] = static fn(WorkflowEnvironment $env, array $input): array => $input;
            } else {
                $key = $param->getName();
                $default = $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null;
                $plan[] = static fn(WorkflowEnvironment $env, array $input): mixed => \array_key_exists($key, $input) ? $input[$key] : $default;
            }
        }

        return $plan;
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
