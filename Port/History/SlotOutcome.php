<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Port\History;

/**
 * An activity or a Nexus operation slot's recorded outcome: its result, or the failure that settled
 * it (`result` is then null). Wrapped rather than returned bare, because a recorded `null` result is
 * a legitimate outcome and must not read as "nothing recorded" (#325).
 */
final readonly class SlotOutcome
{
    public function __construct(
        public mixed $result,
        public ?\Throwable $failed = null,
    ) {}
}
