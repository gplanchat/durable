<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

/**
 * Declares that a contract method is a Nexus operation.
 *
 * **No `Method` suffix, unlike the five other method attributes in the repository.** It is the
 * deliberate exception, and its reason is vocabulary: "operation" is the word Nexus uses
 * everywhere — in the protocol, in Temporal's documentation, in the other languages' SDKs. Someone
 * arriving from the Go SDK looks for an operation, not a service method. The triplet
 * `AsNexusService` / `AsNexusOperation` / `AsNexusServiceHandler` therefore says what Nexus names,
 * rather than the PHP structure that carries it.
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class AsNexusOperation
{
    public function __construct(
        public string $name,
    ) {}
}
