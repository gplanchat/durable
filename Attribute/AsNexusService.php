<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

/**
 * Declares that an interface is the contract of a Nexus service.
 *
 * The name carried here is the one the server routes on: it, together with the name carried by
 * each {@see AsNexusOperation}, is what addresses an incoming task. Nothing else identifies it.
 *
 * The contract is written **once** and serves both roles. The caller derives a typed stub from it;
 * the handler implements whichever of the two contracts carries what it can serve right away.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final readonly class AsNexusService
{
    public function __construct(
        public string $name,
    ) {}
}
