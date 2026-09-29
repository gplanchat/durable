<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

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
final class NexusHandlerDeclarations
{
    /**
     * @param array<class-string, class-string> $handlers    handler => the contract it serves
     * @param list<class-string>                $workflows   the declared workflows, where the
     *                                                        operations they fulfil are read
     * @param \Closure(class-string): object    $instantiate the host's way to get a handler
     * @param string                            $source      where the host declares handlers, for
     *                                                        the refusals to name it
     * @param string                            $contractHint what a wrong contract means on this host
     */
    public function __construct(
        private readonly array $handlers,
        private readonly array $workflows,
        private readonly \Closure $instantiate,
        private readonly string $source,
        private readonly string $contractHint,
    ) {}

    public function registerInto(NexusOperationRegistry $registry): void
    {
        $resolver = new NexusContractResolver(null);
        $claimed = $this->operationsClaimedByWorkflows();

        foreach ($this->handlers as $handlerClass => $contract) {
            if (!interface_exists($contract)) {
                throw new \InvalidArgumentException(\sprintf(
                    'Durable: "%s" is declared as the Nexus contract of %s, but no such interface exists. %s',
                    $contract,
                    $handlerClass,
                    $this->contractHint,
                ));
            }

            $service = NexusService::named($resolver->serviceName($contract));
            $served = 0;

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
                }
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
        }
    }

    /** @return array<class-string, array<string, string>> contract => operation => workflow type */
    private function operationsClaimedByWorkflows(): array
    {
        $claimed = [];

        foreach ($this->workflows as $workflowClass) {
            if (!class_exists($workflowClass)) {
                continue;
            }

            foreach ((new \ReflectionClass($workflowClass))->getAttributes(FulfilsNexusOperation::class) as $attribute) {
                $fulfils = $attribute->newInstance();
                $claimed[$fulfils->contract][$fulfils->operation] = $workflowClass;
            }
        }

        return $claimed;
    }
}
