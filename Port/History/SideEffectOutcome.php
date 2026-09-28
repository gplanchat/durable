<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port\History;

/**
 * A side effect slot's recorded value. Wrapped like its siblings, because a side effect
 * legitimately records `null`: returned bare, "here, the value null" read as "nothing here" and the
 * closure ran again on replay (B1 residue, #325).
 */
final readonly class SideEffectOutcome
{
    public function __construct(
        public mixed $result,
    ) {}
}
