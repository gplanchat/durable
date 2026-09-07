<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * A scheduled timer will not fire (e.g. loser of a {@see \Gplanchat\Durable\WorkflowEnvironment::any()}).
 *
 * A journal marker: the timer stays unresolved, as it does today. It serves to prevent
 * {@see \Gplanchat\Durable\ExecutionRuntime::checkTimers()} and
 * {@see \Gplanchat\Durable\Timer\TimerWakeDelayCalculator} from waking
 * the execution for a dead deadline.
 *
 * ponytail: the replay slot stays consumed by the cancelled timer — settling it as "completed"
 * would make it appear as having *fired* on replay and could designate the wrong winner of an
 * `any()`. Reclaiming the slot would call for named slotting, not positional.
 */
final readonly class TimerCancelled implements Event
{
    public function __construct(
        private string $executionId,
        private string $timerId,
        private string $reason,
    ) {}

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function timerId(): string
    {
        return $this->timerId;
    }

    public function reason(): string
    {
        return $this->reason;
    }

    public function payload(): array
    {
        return [
            'timerId' => $this->timerId,
            'reason' => $this->reason,
        ];
    }
}
