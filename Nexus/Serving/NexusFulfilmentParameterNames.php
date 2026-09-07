<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Nexus\Serving;

use Gplanchat\Durable\Workflow\WorkflowDefinitionLoader;

/**
 * The parameter names of a workflow that fulfils an operation must be those of the contract.
 *
 * **The failure this guard replaces is silent.** The payload of a Nexus operation is keyed by name
 * at both ends: the caller writes it from the contract's signature, and the workflow reads it back
 * through `mapInputToArguments()`. A parameter renamed on one side only breaks nothing at writing
 * time, raises nothing at execution time, and simply arrives as `null`. The only moment where it
 * can still be said is registration, before a task arrives.
 *
 * **Why the guard is here and not in a host.** It was in one: `NexusHandlerPass` carried it
 * privately, so the refusal only existed for Symfony applications. A second serving host —
 * `gplanchat/durable-laravel`, which declares its handlers in `config/durable.php` — would have
 * rewritten it identically, or, more likely, would not have written it at all. Two reflections and
 * one read of `#[AsWorkflowMethod]` require no container: nothing in this check belonged to a
 * framework.
 *
 * **An optional parameter passes**, and that is not a tolerance: giving a default value to a
 * parameter the contract does not carry is a decision — it is saying "if nobody sends it to me,
 * here is what I do". It is the absence of a default that betrays the disappointed expectation.
 */
final class NexusFulfilmentParameterNames
{
    /**
     * @param string       $refusedBy      what the reader must go and fix: the Symfony tag, the
     *                                     Laravel configuration key — the mechanism that refuses,
     *                                     not the class that implements it
     * @param class-string $contract
     * @param class-string $workflowClass
     *
     * @throws \LogicException if a required parameter of the workflow matches nothing
     */
    public static function assertMatch(
        string $refusedBy,
        string $contract,
        string $contractMethod,
        string $operation,
        string $workflowClass,
    ): void {
        $expected = [];
        foreach ((new \ReflectionMethod($contract, $contractMethod))->getParameters() as $parameter) {
            $expected[$parameter->getName()] = true;
        }

        $orphans = [];
        foreach ((new WorkflowDefinitionLoader())->workflowMethodParameters($workflowClass) as $name => $optional) {
            if (!$optional && !isset($expected[$name])) {
                $orphans[] = $name;
            }
        }

        if ([] === $orphans) {
            return;
        }

        throw new \LogicException(\sprintf(
            '%s: workflow %s fulfils operation "%s" of %s, but its parameter(s) $%s match nothing in %s::%s(%s). The payload is keyed by parameter name at both ends, so each of them would silently receive null.',
            $refusedBy,
            $workflowClass,
            $operation,
            $contract,
            implode(', $', $orphans),
            $contract,
            $contractMethod,
            [] === $expected ? '' : '$' . implode(', $', array_keys($expected)),
        ));
    }
}
