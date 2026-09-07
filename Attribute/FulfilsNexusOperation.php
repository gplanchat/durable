<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Attribute;

/**
 * Declares that a workflow fulfils a Nexus operation: its result becomes the operation's result.
 *
 * The declaration lives **on the workflow**, and not on the contract, for two reasons.
 *
 * The contract is read by the caller, which has no business knowing the class that serves it —
 * naming it there would leak the implementation across the very boundary Nexus exists to draw.
 *
 * And this is where the code lives. An operation fulfilled by a workflow has no handler body: the
 * plumbing starts the workflow with the task's callback attached, and the server delivers its
 * result to the caller. Declaring it anywhere else would force writing an empty method just to say
 * there is nothing to write.
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::IS_REPEATABLE)]
final readonly class FulfilsNexusOperation
{
    /**
     * @param class-string $contract
     */
    public function __construct(
        public string $contract,
        public string $operation,
    ) {}
}
