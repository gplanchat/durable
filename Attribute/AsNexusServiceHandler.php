<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

/**
 * Declares that a class serves the operations of a Nexus contract.
 *
 * It names the contract, and nothing else: the service and operation names are read from the
 * contract, once. A typo therefore no longer has two places to slip into.
 *
 * The class **implements** that contract. This is why a contract carrying operations fulfilled by
 * a workflow splits in two: the served interface, which the handler implements, and the one that
 * extends it for the caller. Without that split, PHP would demand method bodies here that nobody
 * wants to write.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsNexusServiceHandler
{
    /**
     * @param class-string $contract
     */
    public function __construct(
        public string $contract,
    ) {}
}
