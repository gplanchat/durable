<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Observation;

use Gplanchat\Durable\Event\Event;
use Gplanchat\Durable\Event\NexusOperationCancelled;
use Gplanchat\Durable\Event\NexusOperationCompleted;
use Gplanchat\Durable\Event\NexusOperationFailed;
use Gplanchat\Durable\Event\NexusOperationScheduled;
use Gplanchat\Durable\Event\NexusOperationTimedOut;

/**
 * One Nexus operation of a run: where it is served, and whether it is settled (#670).
 *
 * Every dashboard shows the operations a run waits on, the way it shows its activities: the
 * endpoint, service and operation say *where* the wait is. The journal has no "started" event, so
 * an operation is in flight from its scheduling until an outcome carries its scheduled event id.
 */
final readonly class NexusOperationSummary
{
    public function __construct(
        public string $endpoint,
        public string $service,
        public string $operation,
        public NexusOperationState $state,
    ) {}

    /**
     * @param iterable<Event> $history a run's journal, in order
     *
     * @return list<self> in scheduling order
     */
    public static function of(iterable $history): array
    {
        /** @var array<int, NexusOperationScheduled> $scheduled */
        $scheduled = [];
        /** @var array<int, NexusOperationState> $settled */
        $settled = [];

        foreach ($history as $event) {
            if ($event instanceof NexusOperationScheduled) {
                $scheduled[$event->scheduledEventId()] = $event;

                continue;
            }
            $state = match (true) {
                $event instanceof NexusOperationCompleted => NexusOperationState::Completed,
                $event instanceof NexusOperationFailed => NexusOperationState::Failed,
                $event instanceof NexusOperationTimedOut => NexusOperationState::TimedOut,
                $event instanceof NexusOperationCancelled => NexusOperationState::Cancelled,
                default => null,
            };
            if (null !== $state) {
                $settled[$event->scheduledEventId()] = $state;
            }
        }

        $operations = [];
        foreach ($scheduled as $id => $event) {
            $operations[] = new self($event->endpoint(), $event->service(), $event->operation(), $settled[$id] ?? NexusOperationState::InFlight);
        }

        return $operations;
    }
}
