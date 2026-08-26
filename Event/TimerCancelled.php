<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Event;

/**
 * Un minuteur planifié ne partira pas (ex. perdant d'un {@see \Gplanchat\Durable\WorkflowEnvironment::any()}).
 *
 * Marqueur de journal : le minuteur reste non résolu, comme aujourd'hui. Il sert à empêcher
 * {@see \Gplanchat\Durable\ExecutionRuntime::checkTimers()} et
 * {@see \Gplanchat\Durable\Bundle\Messenger\TimerWakeDelayCalculator} de réveiller
 * l'exécution pour une échéance morte.
 *
 * ponytail: le slot de replay reste consommé par le minuteur annulé — le régler comme
 * « terminé » le ferait apparaître comme *parti* au replay et pourrait désigner le mauvais
 * gagnant d'un `any()`. Réclamer le slot demanderait un slotting nommé, pas positionnel.
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
