<?php

declare(strict_types=1);

namespace Gplanchat\Durable\Transport;

use Gplanchat\Durable\Event\ChildWorkflowCompleted;
use Gplanchat\Durable\Event\ChildWorkflowFailed;
use Gplanchat\Durable\Event\TimerCancelled;
use Gplanchat\Durable\Event\TimerCompleted;
use Gplanchat\Durable\Event\WorkflowSignalReceived;
use Gplanchat\Durable\Store\ActivityEventJournal;
use Gplanchat\Durable\Store\EventStoreInterface;

/**
 * What a resume sent before its fact announces, and waits for (DUR050, DUR052).
 *
 * A resume that finds its fact missing from the journal concludes nothing: the transport's retry
 * is the wait. Serialized with the message, hence plain strings and an enum.
 */
final readonly class AwaitedFact
{
    /**
     * Public so that a denormalizer can rebuild the fact: a transport configured with the Symfony
     * Serializer carries the message as JSON (#627). In code, prefer the named factories below.
     *
     * @param non-empty-list<string> $ids one id for an activity, a child or a signal; one per
     *        timer for timers
     *
     * @throws \InvalidArgumentException when a kind other than timers is given more than one id
     */
    public function __construct(
        public AwaitedFactKind $kind,
        public array $ids,
    ) {
        if (AwaitedFactKind::Timer !== $kind && 1 !== \count($ids)) {
            throw new \InvalidArgumentException(\sprintf('An awaited %s has exactly one id, got %d.', $kind->value, \count($ids)));
        }
    }

    public static function activity(string $activityId): self
    {
        return new self(AwaitedFactKind::Activity, [$activityId]);
    }

    public static function child(string $childExecutionId): self
    {
        return new self(AwaitedFactKind::Child, [$childExecutionId]);
    }

    public static function signal(string $requestId): self
    {
        return new self(AwaitedFactKind::Signal, [$requestId]);
    }

    /**
     * @param non-empty-list<string> $timerIds
     */
    public static function timers(array $timerIds): self
    {
        return new self(AwaitedFactKind::Timer, $timerIds);
    }

    public function isJournalledIn(EventStoreInterface $journal, string $executionId): bool
    {
        if (AwaitedFactKind::Activity === $this->kind) {
            return ActivityEventJournal::hasTerminalOutcomeForActivity($journal, $executionId, $this->ids[0]);
        }

        $missing = array_fill_keys($this->ids, true);
        foreach ($journal->readStream($executionId) as $event) {
            $id = match (true) {
                AwaitedFactKind::Child === $this->kind && ($event instanceof ChildWorkflowCompleted || $event instanceof ChildWorkflowFailed) => $event->childExecutionId(),
                AwaitedFactKind::Signal === $this->kind && $event instanceof WorkflowSignalReceived => $event->requestId(),
                // A named timer may be cancelled before it fires; that settles it too.
                AwaitedFactKind::Timer === $this->kind && ($event instanceof TimerCompleted || $event instanceof TimerCancelled) => $event->timerId(),
                default => null,
            };
            unset($missing[$id ?? '']);
        }

        return [] === $missing;
    }

    public function describe(): string
    {
        return $this->kind->value . ' ' . implode(', ', $this->ids);
    }
}
