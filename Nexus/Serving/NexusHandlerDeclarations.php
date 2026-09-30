<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Attribute\AsNexusServiceHandler;
use Gplanchat\Durable\Attribute\FulfilsNexusOperation;
use Gplanchat\Durable\Nexus\NexusOperationName;
use Gplanchat\Durable\Nexus\NexusService;
use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * What a host declares it serves in Nexus, carried into the registry: the one path for the hosts
 * that have no compile pass — Laravel's `config/durable.php`, Magento's di.xml (#668).
 *
 * It does what `NexusHandlerPass` does on Symfony, by the same pieces: `NexusContractResolver`
 * reads the contract, `NexusHandlerInvoker` holds between the handler's signature and what the
 * registry calls. **An operation without a body is not a missing operation:** a workflow that
 * carries `#[FulfilsNexusOperation]` fulfils it, and what is registered is its **type** — the name
 * the server knows and the journal records.
 */
final readonly class NexusHandlerDeclarations
{
    /**
     * @param array<array-key, class-string> $handlers       handler => the contract it serves, or a
     *                                                        handler alone, whose contract its
     *                                                        #[AsNexusServiceHandler] names
     * @param list<class-string>             $workflows      the declared workflows, where the
     *                                                        operations they fulfil are read
     * @param \Closure(class-string): object $instantiate    the host's way to get a handler
     * @param string                         $source         where the host declares handlers, for
     *                                                        the refusals to name it
     * @param string                         $contractHint   what a wrong contract means on this host
     * @param string                         $workflowSource where the host declares workflows
     */
    public function __construct(
        private readonly array $handlers,
        private readonly array $workflows,
        private readonly \Closure $instantiate,
        private readonly string $source,
        private readonly string $contractHint,
        private readonly string $workflowSource,
    ) {}

    public function registerInto(NexusOperationRegistry $registry): void
    {
        $resolver = new NexusContractResolver(null);
        $claimed = $this->operationsClaimedByWorkflows();

        foreach ($this->handlers as $key => $value) {
            [$handlerClass, $contract] = \is_int($key) ? [$value, $this->contractNamedBy($value)] : [$key, $value];
            if (!interface_exists($contract)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Durable: "%s" is declared as the Nexus contract of %s, but no such interface exists. %s',
                    $contract,
                    $handlerClass,
                    $this->contractHint,
                ));
            }

            $named = \is_int($key) ? $contract : $this->contractNamedBy($handlerClass, required: false);
            if (null !== $named && $named !== $contract) {
                throw new \InvalidArgumentException(\sprintf(
                    'Durable: %s gives %s the contract %s, but its #[AsNexusServiceHandler] names %s.',
                    $this->source,
                    $handlerClass,
                    $contract,
                    $named,
                ));
            }

            $service = NexusService::named($resolver->serviceName($contract));
            $served = 0;
            $unserved = [];

            foreach ($resolver->operations($contract) as $method => $operation) {
                $name = NexusOperationName::named($operation);

                if (method_exists($handlerClass, $method)) {
                    $invoker = new NexusHandlerInvoker(($this->instantiate)($handlerClass), $contract, $method);
                    $registry->register($service, $name, $invoker(...));
                    ++$served;

                    continue;
                }

                $workflowClass = $claimed[$contract][$operation] ?? null;
                if (null !== $workflowClass) {
                    // The same refusal as on the Symfony side, by the same class: reading a list
                    // from a file does not excuse checking what a compiler pass checks. It falls
                    // here, at registration, and not on the first task — that is the last moment
                    // where somebody is looking.
                    NexusFulfilmentParameterNames::assertMatch(
                        $this->source,
                        $contract,
                        $method,
                        $operation,
                        $workflowClass,
                    );

                    // The **type**, not the FQCN: that is the name the server knows and that the
                    // journal records.
                    $registry->registerFulfilment(
                        $service,
                        $name,
                        (new WorkflowDefinitionLoader())->workflowTypeForClass($workflowClass),
                    );
                    ++$served;

                    continue;
                }

                $unserved[$operation] = $method . '()';
            }

            if (0 === $served) {
                throw new \InvalidArgumentException(\sprintf(
                    'Durable: %s serves none of the operations of %s — neither a method nor a workflow '
                    . 'carrying #[FulfilsNexusOperation] answers for any of them. A handler that serves '
                    . 'nothing is a declaration nobody will notice is dead.',
                    $handlerClass,
                    $contract,
                ));
            }

            // Symfony's NexusHandlerPass refuses the same at compile time: a caller would wait on a
            // result nothing produces.
            if ([] !== $unserved) {
                throw new \InvalidArgumentException(\sprintf(
                    'Durable: operation "%s" of contract %s is served by nobody — handler %s does not implement '
                    . '%s and no workflow claims it with #[FulfilsNexusOperation]. A caller would wait on a result '
                    . 'nothing produces.',
                    implode('", "', array_keys($unserved)),
                    $contract,
                    $handlerClass,
                    implode(', ', $unserved),
                ));
            }
        }
    }

    /** @return ($required is true ? class-string : class-string|null) */
    private function contractNamedBy(string $handlerClass, bool $required = true): ?string
    {
        if (!class_exists($handlerClass)) {
            throw new \InvalidArgumentException(\sprintf('Durable: "%s" is declared in %s, but no such class exists.', $handlerClass, $this->source));
        }

        $attributes = (new \ReflectionClass($handlerClass))->getAttributes(AsNexusServiceHandler::class);
        if ([] === $attributes && $required) {
            throw new \InvalidArgumentException(\sprintf(
                'Durable: %s is listed alone in %s, so its contract must come from '
                . '#[AsNexusServiceHandler]. Add the attribute, or declare it as handler => contract.',
                $handlerClass,
                $this->source,
            ));
        }

        return [] === $attributes ? null : $attributes[0]->newInstance()->contract;
    }

    /** @return array<class-string, array<string, string>> contract => operation => workflow type */
    private function operationsClaimedByWorkflows(): array
    {
        $claimed = [];

        foreach ($this->workflows as $workflowClass) {
            if (!class_exists($workflowClass)) {
                throw new \InvalidArgumentException(\sprintf('Durable: "%s" is declared in %s, but no such class exists.', $workflowClass, $this->workflowSource));
            }

            foreach ((new \ReflectionClass($workflowClass))->getAttributes(FulfilsNexusOperation::class) as $attribute) {
                $fulfils = $attribute->newInstance();
                $claimed[$fulfils->contract][$fulfils->operation] = $workflowClass;
            }
        }

        return $claimed;
    }
}
