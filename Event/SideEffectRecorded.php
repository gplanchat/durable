<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Result of a {@see \Gplanchat\Durable\ExecutionContext::sideEffect()} call persisted in the journal.
 *
 * On replay, the side effect closure is not re-executed; the value recorded here is reused
 * (aligned with Temporal {@link https://docs.temporal.io/develop/php/side-effects}).
 */
final readonly class SideEffectRecorded implements Event
{
    public function __construct(
        private string $executionId,
        private string $sideEffectId,
        private mixed $result,
    ) {}

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function sideEffectId(): string
    {
        return $this->sideEffectId;
    }

    public function result(): mixed
    {
        return $this->result;
    }

    public function payload(): array
    {
        return [
            'sideEffectId' => $this->sideEffectId,
            'result' => $this->result,
        ];
    }
}
