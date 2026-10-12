<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Workflow;

/**
 * One method of a workflow class the engine calls: the entry point, a signal, a query or an update.
 */
final readonly class WorkflowHandler
{
    /**
     * @param list<string> $parameters each as `type $name`, with ` = …` when it has a default
     */
    public function __construct(
        public string $method,
        public array $parameters,
        public ?string $returnType,
    ) {}

    public static function fromMethod(\ReflectionMethod $method, bool $injectedHidden = false): self
    {
        $parameters = [];
        foreach ($injectedHidden ? WorkflowDefinitionLoader::inputParameters($method) : $method->getParameters() as $parameter) {
            $parameters[] = \sprintf(
                '%s%s$%s%s',
                null === $parameter->getType() ? '' : (string) $parameter->getType() . ' ',
                $parameter->isVariadic() ? '...' : '',
                $parameter->getName(),
                $parameter->isDefaultValueAvailable() ? ' = …' : '',
            );
        }

        return new self($method->getName(), $parameters, null === $method->getReturnType() ? null : (string) $method->getReturnType());
    }

    public function signature(): string
    {
        return \sprintf('%s(%s)%s', $this->method, implode(', ', $this->parameters), null === $this->returnType ? '' : ': ' . $this->returnType);
    }
}
